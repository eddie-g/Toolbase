#!/usr/bin/env python3
"""
Build the in-house fillable form templates in resources/forms.

Each template is a Letter-size PDF whose blanks are real AcroForm fields
(text boxes, check boxes, signature and date lines), so the pdf.js editor
fills them the same way it fills the official IRS forms and the download
writes the values into the form's own fields.

Usage:
    python3 generate_fillable_forms.py <output_dir> [slug ...]
    python3 generate_fillable_forms.py --check <pdf ...>

Slugs: lease-agreement, invoice, bill-of-sale, nda, power-of-attorney,
       liability-waiver, employment-contract
"""

import os
import sys

import fitz  # PyMuPDF

PAGE_W, PAGE_H = 612.0, 792.0
MARGIN = 54.0
CONTENT_W = PAGE_W - 2 * MARGIN

INK = (0.09, 0.09, 0.11)
MUTED = (0.42, 0.42, 0.46)
RULE = (0.80, 0.80, 0.83)
FIELD_FILL = (0.95, 0.96, 0.99)
FIELD_BORDER = (0.70, 0.72, 0.78)
ACCENT = (0.15, 0.39, 0.92)

FONT = "helv"
FONT_BOLD = "hebo"


class Sheet:
    """A cursor-driven page builder: headings, paragraphs, labeled fields."""

    def __init__(self, doc, title, subtitle=None):
        self.doc = doc
        self.title = title
        self.subtitle = subtitle
        self.page = None
        self.y = 0.0
        self.field_index = 0
        self.new_page()

    # ── page management ──────────────────────────────────────────────
    def new_page(self):
        self.page = self.doc.new_page(width=PAGE_W, height=PAGE_H)
        self.y = MARGIN
        if self.doc.page_count == 1:
            self._title_block()
        else:
            self._running_header()

    def ensure(self, height):
        if self.y + height > PAGE_H - MARGIN - 24:
            self.new_page()

    def _title_block(self):
        self.page.insert_text((MARGIN, self.y + 20), self.title, fontname=FONT_BOLD, fontsize=20, color=INK)
        self.y += 30
        if self.subtitle:
            self.page.insert_text((MARGIN, self.y + 8), self.subtitle, fontname=FONT, fontsize=10, color=MUTED)
            self.y += 16
        self.rule(ACCENT, 1.4)
        self.y += 10

    def _running_header(self):
        self.page.insert_text((MARGIN, self.y + 4), self.title, fontname=FONT, fontsize=8.5, color=MUTED)
        self.page.insert_text((PAGE_W - MARGIN - 40, self.y + 4), f"Page {self.doc.page_count}", fontname=FONT, fontsize=8.5, color=MUTED)
        self.y += 12
        self.rule(RULE, 0.6)
        self.y += 10

    def rule(self, color=RULE, width=0.6):
        shape = self.page.new_shape()
        shape.draw_line((MARGIN, self.y), (PAGE_W - MARGIN, self.y))
        shape.finish(color=color, width=width)
        shape.commit()
        self.y += 2

    # ── text ─────────────────────────────────────────────────────────
    def heading(self, text):
        self.ensure(34)
        self.y += 10
        self.page.insert_text((MARGIN, self.y + 11), text.upper(), fontname=FONT_BOLD, fontsize=9.5, color=ACCENT)
        self.y += 18

    def paragraph(self, text, size=9.5, color=INK, gap=6):
        height = self._text_height(text, size, CONTENT_W)
        self.ensure(height + gap)
        rect = fitz.Rect(MARGIN, self.y, PAGE_W - MARGIN, self.y + height + 4)
        self.page.insert_textbox(rect, text, fontname=FONT, fontsize=size, color=color, align=fitz.TEXT_ALIGN_LEFT, lineheight=1.35)
        self.y += height + gap

    def note(self, text):
        self.paragraph(text, size=8, color=MUTED, gap=8)

    def _text_height(self, text, size, width):
        # Measure with a throwaway textbox on a scratch rect.
        probe = fitz.Rect(0, 0, width, 4000)
        scratch = fitz.open()
        page = scratch.new_page(width=PAGE_W, height=4200)
        leftover = page.insert_textbox(probe, text, fontname=FONT, fontsize=size, lineheight=1.35)
        used = 4000 - leftover if leftover >= 0 else 4000
        scratch.close()
        return max(size * 1.35, used)

    # ── fields ───────────────────────────────────────────────────────
    def _name(self, label):
        self.field_index += 1
        slug = "".join(ch if ch.isalnum() else "_" for ch in label.lower()).strip("_")
        return f"{slug}_{self.field_index}"

    def fields(self, labels, height=22, gap=8):
        """A row of labeled text fields sharing the content width."""
        self.ensure(height + 22)
        count = len(labels)
        gutter = 12
        width = (CONTENT_W - gutter * (count - 1)) / count
        x = MARGIN
        for spec in labels:
            label, options = (spec, {}) if isinstance(spec, str) else spec
            self.page.insert_text((x, self.y + 8), label, fontname=FONT, fontsize=7.5, color=MUTED)
            rect = fitz.Rect(x, self.y + 11, x + width, self.y + 11 + height)
            self._text_field(self._name(label), rect, multiline=options.get("multiline", False),
                             fontsize=options.get("fontsize", 9.5), align=options.get("align", fitz.TEXT_ALIGN_LEFT))
            x += width + gutter
        self.y += height + 11 + gap

    def textarea(self, label, height=54):
        self.fields([(label, {"multiline": True, "fontsize": 9})], height=height, gap=10)

    def inline(self, before, label, width, after="", height=16):
        """Text with a field inside it, e.g. 'for a term of [ ] months'."""
        self.ensure(height + 8)
        x = MARGIN
        if before:
            self.page.insert_text((x, self.y + 12), before, fontname=FONT, fontsize=9.5, color=INK)
            x += fitz.get_text_length(before, fontname=FONT, fontsize=9.5) + 6
        rect = fitz.Rect(x, self.y + 1, x + width, self.y + 1 + height)
        self._text_field(self._name(label), rect, fontsize=9)
        x += width + 6
        if after:
            self.page.insert_text((x, self.y + 12), after, fontname=FONT, fontsize=9.5, color=INK)
        self.y += height + 8

    def checkboxes(self, label, options, columns=2):
        self.ensure(20 + 16 * ((len(options) + columns - 1) // columns))
        if label:
            self.page.insert_text((MARGIN, self.y + 8), label, fontname=FONT, fontsize=7.5, color=MUTED)
            self.y += 12
        col_w = CONTENT_W / columns
        for index, option in enumerate(options):
            col = index % columns
            if col == 0 and index > 0:
                self.y += 16
            x = MARGIN + col * col_w
            rect = fitz.Rect(x, self.y + 2, x + 11, self.y + 13)
            self._checkbox(self._name(option), rect)
            self.page.insert_text((x + 16, self.y + 11), option, fontname=FONT, fontsize=9.2, color=INK)
        self.y += 24

    def signatures(self, parties):
        """Signature, printed name and date for each party, two per row."""
        rows = [parties[i:i + 2] for i in range(0, len(parties), 2)]
        for row in rows:
            self.ensure(96)
            col_w = (CONTENT_W - 24) / 2
            for col, party in enumerate(row):
                x = MARGIN + col * (col_w + 24)
                self.page.insert_text((x, self.y + 10), party, fontname=FONT_BOLD, fontsize=9, color=INK)
                y = self.y + 16
                for label, h in (("Signature", 26), ("Printed name", 18), ("Date", 18)):
                    self.page.insert_text((x, y + 8), label, fontname=FONT, fontsize=7.5, color=MUTED)
                    rect = fitz.Rect(x, y + 11, x + col_w, y + 11 + h)
                    self._text_field(self._name(f"{party} {label}"), rect, fontsize=9)
                    y += h + 12
            self.y += 96 + 8

    def _text_field(self, name, rect, multiline=False, fontsize=9.5, align=fitz.TEXT_ALIGN_LEFT):
        widget = fitz.Widget()
        widget.field_name = name
        widget.field_type = fitz.PDF_WIDGET_TYPE_TEXT
        widget.rect = rect
        widget.text_font = "Helv"
        widget.text_fontsize = float(fontsize)
        widget.text_color = INK
        widget.fill_color = FIELD_FILL
        widget.border_color = FIELD_BORDER
        widget.border_width = 0.6
        widget.field_value = ""
        if multiline:
            widget.field_flags = fitz.PDF_TX_FIELD_IS_MULTILINE
        try:
            widget.text_align = align
        except Exception:
            pass
        self.page.add_widget(widget)

    def _checkbox(self, name, rect):
        widget = fitz.Widget()
        widget.field_name = name
        widget.field_type = fitz.PDF_WIDGET_TYPE_CHECKBOX
        widget.rect = rect
        widget.fill_color = FIELD_FILL
        widget.border_color = FIELD_BORDER
        widget.border_width = 0.8
        widget.field_value = False
        self.page.add_widget(widget)


# ── templates ────────────────────────────────────────────────────────

REVIEW_NOTE = ("This is a general template provided for convenience. It is not legal advice. "
               "Laws differ by state and country; have the completed document reviewed by a qualified professional before relying on it.")


def lease_agreement(doc):
    s = Sheet(doc, "Residential Lease Agreement", "Fixed-term lease between a landlord and one or more tenants")
    s.heading("1. Parties")
    s.paragraph("This Residential Lease Agreement (the \"Lease\") is made between the Landlord and the Tenant(s) named below.")
    s.fields(["Landlord (full legal name)", "Landlord phone / email"])
    s.fields(["Tenant 1 (full legal name)", "Tenant 2 (full legal name)"])
    s.heading("2. Premises")
    s.fields([("Address of the premises", {}), "Unit / apartment"])
    s.fields(["City", "State / province", "ZIP / postal code"])
    s.checkboxes("Included with the premises", ["Parking space", "Storage unit", "Furnished", "Appliances (list below)"], columns=4)
    s.textarea("Furnishings and appliances included", height=40)
    s.heading("3. Term")
    s.fields(["Lease start date", "Lease end date", "Term (months)"])
    s.checkboxes("At the end of the term the Lease", ["Ends and the Tenant vacates", "Continues month to month unless either party gives 30 days' notice"], columns=1)
    s.heading("4. Rent")
    s.fields(["Monthly rent ($)", "Due on the (day of month)", "Late fee ($)", "Late after (days)"])
    s.fields(["Payment method(s) accepted", "Pay to (name and address)"])
    s.heading("5. Security Deposit")
    s.inline("The Tenant will pay a security deposit of $", "Security deposit amount", 90, "on or before the start date.")
    s.paragraph("The deposit will be returned, less lawful deductions for unpaid rent or damage beyond normal wear, within the period required by law after the Tenant vacates.")
    s.heading("6. Utilities and Services")
    s.checkboxes("Paid by the Landlord", ["Water", "Electricity", "Gas", "Trash", "Internet", "Other (below)"], columns=3)
    s.fields(["Utilities paid by the Tenant", "Other arrangements"])
    s.heading("7. Occupancy, Pets and Use")
    s.fields(["Maximum number of occupants", "Names of other occupants"])
    s.checkboxes("Pets", ["No pets", "Pets allowed as described below"], columns=2)
    s.fields(["Pet description and any pet deposit ($)"])
    s.paragraph("The premises will be used as a private residence only. The Tenant will keep the premises clean and safe, will not disturb neighbours, and will not sublet or assign the Lease without the Landlord's written consent.")
    s.heading("8. Maintenance and Entry")
    s.paragraph("The Landlord will keep the premises fit for habitation and will make repairs within a reasonable time after notice. The Tenant will promptly report needed repairs and will be responsible for damage caused by the Tenant or guests. The Landlord may enter with at least 24 hours' notice for repairs, inspections or showings, and at any time in an emergency.")
    s.heading("9. Default and Termination")
    s.paragraph("If rent is unpaid or the Tenant breaches this Lease and does not cure within the notice period required by law, the Landlord may end the Lease and recover possession as the law allows. Either party may end the Lease early only as permitted by law or by written agreement.")
    s.heading("10. Additional Terms")
    s.textarea("Additional terms agreed by the parties", height=70)
    s.fields(["Governing state / jurisdiction"])
    s.note(REVIEW_NOTE)
    s.heading("Signatures")
    s.signatures(["Landlord", "Tenant 1", "Tenant 2"])


def invoice(doc):
    s = Sheet(doc, "Invoice", "Fillable invoice for goods or services")
    s.fields(["Invoice number", "Invoice date", "Due date", ("Amount due ($)", {"align": fitz.TEXT_ALIGN_RIGHT})])
    s.heading("From")
    s.fields(["Business name", "Contact name"])
    s.fields([("Address", {"multiline": True})], height=40)
    s.fields(["Email", "Phone", "Tax / VAT number"])
    s.heading("Bill To")
    s.fields(["Customer name", "Attention"])
    s.fields([("Address", {"multiline": True})], height=40)
    s.fields(["Email", "Phone", "Customer reference / PO"])
    s.heading("Items")
    # Column headers
    s.ensure(30)
    cols = [("Description", 0.52), ("Qty", 0.12), ("Rate ($)", 0.16), ("Amount ($)", 0.20)]
    x = MARGIN
    for label, frac in cols:
        s.page.insert_text((x + 2, s.y + 9), label, fontname=FONT_BOLD, fontsize=8, color=MUTED)
        x += CONTENT_W * frac
    s.y += 13
    s.rule()
    s.y += 4
    for row in range(8):
        s.ensure(24)
        x = MARGIN
        for label, frac in cols:
            width = CONTENT_W * frac - 6
            rect = fitz.Rect(x, s.y, x + width, s.y + 18)
            align = fitz.TEXT_ALIGN_RIGHT if label != "Description" else fitz.TEXT_ALIGN_LEFT
            s._text_field(s._name(f"line {row + 1} {label}"), rect, fontsize=9, align=align)
            x += CONTENT_W * frac
        s.y += 22
    s.y += 4
    s.rule()
    s.y += 6
    for label in ("Subtotal ($)", "Discount ($)", "Tax rate (%)", "Tax ($)", "Shipping ($)", "Total due ($)"):
        s.ensure(22)
        x = MARGIN + CONTENT_W * 0.58
        s.page.insert_text((x, s.y + 12), label, fontname=FONT_BOLD if label.startswith("Total") else FONT, fontsize=9, color=INK)
        rect = fitz.Rect(MARGIN + CONTENT_W * 0.80, s.y, PAGE_W - MARGIN, s.y + 18)
        s._text_field(s._name(label), rect, fontsize=9.5, align=fitz.TEXT_ALIGN_RIGHT)
        s.y += 22
    s.heading("Payment")
    s.fields(["Payment terms (e.g. Net 30)", "Accepted methods"])
    s.fields(["Bank / account details"])
    s.textarea("Notes to the customer", height=48)


def bill_of_sale(doc):
    s = Sheet(doc, "Bill of Sale", "Transfer of ownership of personal property from a seller to a buyer")
    s.heading("1. Seller")
    s.fields(["Seller full legal name", "Seller phone / email"])
    s.fields([("Seller address", {})])
    s.heading("2. Buyer")
    s.fields(["Buyer full legal name", "Buyer phone / email"])
    s.fields([("Buyer address", {})])
    s.heading("3. Property Sold")
    s.textarea("Description of the item(s) sold", height=48)
    s.fields(["Make / brand", "Model", "Year"])
    s.fields(["Serial number / VIN", "Colour", "Odometer / hours (if any)"])
    s.heading("4. Price and Payment")
    s.inline("The Buyer agrees to pay the Seller the total sum of $", "Purchase price", 100, "for the property described above.")
    s.checkboxes("Payment", ["Paid in full on the date below", "Deposit paid, balance due by the date below", "Trade-in accepted as part payment"], columns=1)
    s.fields(["Deposit amount ($)", "Balance due date", "Payment method"])
    s.heading("5. Condition and Warranties")
    s.checkboxes("", ["Sold \"as is\", with no warranty of any kind", "Sold with the warranty described below"], columns=1)
    s.textarea("Warranty, known defects or disclosures", height=44)
    s.paragraph("The Seller confirms that the Seller is the lawful owner of the property, that it is free of liens and claims except as disclosed above, and that the Seller has the right to sell it. Ownership and risk of loss pass to the Buyer on delivery and payment as set out above.")
    s.fields(["Date of sale", "Place of delivery"])
    s.note(REVIEW_NOTE)
    s.heading("Signatures")
    s.signatures(["Seller", "Buyer", "Witness"])


def nda(doc):
    s = Sheet(doc, "Mutual Non-Disclosure Agreement", "Protects confidential information exchanged between two parties")
    s.heading("1. Parties and Effective Date")
    s.fields(["Party A (legal name)", "Party B (legal name)"])
    s.fields(["Party A address", "Party B address"])
    s.fields(["Effective date", "Purpose of the disclosure (e.g. evaluating a partnership)"])
    s.heading("2. Confidential Information")
    s.paragraph("\"Confidential Information\" means any non-public business, technical or financial information disclosed by either party (the \"Disclosing Party\") to the other (the \"Receiving Party\"), whether marked as confidential or reasonably understood to be so, including products, plans, customers, pricing, code, designs and know-how. It does not include information that is or becomes public through no fault of the Receiving Party, was already known to it, is independently developed, or is lawfully received from a third party.")
    s.heading("3. Obligations")
    s.paragraph("The Receiving Party will use Confidential Information only for the Purpose, will protect it with at least the care it uses for its own confidential information and no less than reasonable care, and will disclose it only to employees, contractors and advisers who need to know it and are bound by obligations at least as protective as these. The Receiving Party will notify the Disclosing Party promptly of any unauthorised use or disclosure.")
    s.heading("4. Compelled Disclosure")
    s.paragraph("If the Receiving Party is required by law or court order to disclose Confidential Information, it will give the Disclosing Party prompt notice (where lawful) so that the Disclosing Party may seek protection, and will disclose only what is legally required.")
    s.heading("5. Term")
    s.inline("This Agreement lasts for", "Term in years", 50, "year(s) from the Effective Date. The confidentiality obligations survive for")
    s.inline("", "Survival in years", 50, "year(s) after it ends; obligations for trade secrets survive as long as they remain trade secrets.")
    s.heading("6. Return of Materials, Remedies and General Terms")
    s.paragraph("On request or when this Agreement ends, the Receiving Party will return or destroy all Confidential Information. Each party acknowledges that a breach may cause irreparable harm for which damages are not an adequate remedy, so the Disclosing Party may seek injunctive relief in addition to other remedies. Nothing in this Agreement grants any licence or obliges either party to enter into any further agreement. This Agreement is the entire agreement on its subject and may only be changed in writing signed by both parties.")
    s.fields(["Governing state / country", "Courts with jurisdiction"])
    s.note(REVIEW_NOTE)
    s.heading("Signatures")
    s.signatures(["Party A", "Party B"])
    s.fields(["Party A signatory title", "Party B signatory title"])


def power_of_attorney(doc):
    s = Sheet(doc, "General Power of Attorney", "Appoints an agent to act on the principal's behalf")
    s.heading("1. Principal")
    s.fields(["Principal full legal name", "Date of birth"])
    s.fields([("Principal address", {})])
    s.heading("2. Agent (Attorney-in-Fact)")
    s.fields(["Agent full legal name", "Agent phone / email"])
    s.fields([("Agent address", {})])
    s.fields(["Alternate agent (if the agent cannot serve)", "Alternate agent phone / email"])
    s.heading("3. Powers Granted")
    s.paragraph("I, the Principal, appoint the Agent to act for me in my name and on my behalf in the matters checked below, with full authority to do everything I could do myself in those matters, subject to any limits written in section 4.")
    s.checkboxes("", [
        "Real estate transactions", "Banking and financial accounts",
        "Personal property and goods", "Stocks, bonds and investments",
        "Business operating transactions", "Insurance and annuities",
        "Claims and litigation", "Tax matters",
        "Government benefits", "Retirement plans",
        "Gifts (as limited below)", "Digital assets and accounts",
    ], columns=2)
    s.heading("4. Limits and Special Instructions")
    s.textarea("Limits, conditions or special instructions", height=60)
    s.heading("5. Effective Date and Duration")
    s.checkboxes("This power of attorney", [
        "Takes effect immediately",
        "Takes effect only when a physician certifies in writing that I am incapacitated",
    ], columns=1)
    s.checkboxes("It", [
        "Continues even if I become incapacitated (durable)",
        "Ends if I become incapacitated",
    ], columns=1)
    s.fields(["Date it ends (leave blank if until revoked)"])
    s.paragraph("I may revoke this power of attorney at any time by written notice to the Agent. Third parties may rely on the Agent's authority until they receive notice of revocation. The Agent will act in my best interest, keep my property separate from the Agent's own, and keep records of all transactions.")
    s.note(REVIEW_NOTE + " Many jurisdictions require witnesses or notarisation for a power of attorney to be valid.")
    s.heading("Signatures")
    s.signatures(["Principal", "Agent (acceptance)", "Witness 1", "Witness 2"])
    s.heading("Notary Acknowledgement")
    s.fields(["State / county", "Date acknowledged"])
    s.fields(["Notary public name", "Commission expires"])
    s.fields([("Notary signature", {})], height=28)


def liability_waiver(doc):
    s = Sheet(doc, "Release and Waiver of Liability", "Assumption of risk and release for participation in an activity")
    s.heading("1. Activity")
    s.fields(["Organisation / host", "Activity or event"])
    s.fields(["Location", "Date(s) of the activity"])
    s.heading("2. Participant")
    s.fields(["Participant full name", "Date of birth", "Phone"])
    s.fields([("Address", {}), "Email"])
    s.fields(["Emergency contact name", "Relationship", "Emergency contact phone"])
    s.fields(["Medical conditions, allergies or medication the host should know about"])
    s.heading("3. Assumption of Risk")
    s.paragraph("I understand that the Activity involves risks, including the risk of injury, illness, property damage and, in extreme cases, death, arising from the Activity itself, the conduct of other participants, the condition of the premises or equipment, and the negligence of others. I voluntarily accept these risks.")
    s.heading("4. Release")
    s.paragraph("In exchange for being allowed to take part, I release and agree not to sue the Organisation, its owners, staff, volunteers, sponsors and venue (the \"Released Parties\") from any claim for injury, loss or damage arising from my participation, to the fullest extent the law allows, including claims based on the ordinary negligence of the Released Parties. I will indemnify the Released Parties against claims brought by others because of my conduct.")
    s.heading("5. Medical Treatment, Photos and Rules")
    s.paragraph("I consent to first aid and emergency medical treatment if needed and accept responsibility for its cost. I agree to follow the host's rules and instructions and may be removed for not doing so. I grant the Organisation permission to use photos or video taken during the Activity unless I tick the box below.")
    s.checkboxes("", [
        "I have read this waiver, understand it, and sign it voluntarily",
        "I am 18 or older (or my parent / guardian signs below)",
        "Do not use my photo or video",
    ], columns=1)
    s.fields(["Governing state / country"])
    s.note(REVIEW_NOTE)
    s.heading("Signatures")
    s.signatures(["Participant", "Parent / guardian (if under 18)"])


def employment_contract(doc):
    s = Sheet(doc, "Employment Agreement", "Terms of employment between an employer and an employee")
    s.heading("1. Parties")
    s.fields(["Employer (legal name)", "Employer address"])
    s.fields(["Employee (full legal name)", "Employee address"])
    s.heading("2. Position")
    s.fields(["Job title", "Department", "Reports to"])
    s.fields(["Start date", "Work location", "Working hours per week"])
    s.checkboxes("Employment type", ["Full time", "Part time", "Fixed term (end date below)", "Casual / as needed"], columns=4)
    s.fields(["Fixed-term end date (if any)", "Probation period (months)"])
    s.textarea("Main duties and responsibilities", height=56)
    s.heading("3. Compensation")
    s.fields(["Base pay ($)", "Per (hour / year)", "Pay frequency", "First pay date"])
    s.fields(["Bonus or commission terms", "Overtime terms"])
    s.heading("4. Benefits and Leave")
    s.checkboxes("", ["Health insurance", "Retirement plan", "Paid vacation (days below)", "Paid sick leave", "Remote / hybrid work", "Other (below)"], columns=3)
    s.fields(["Paid vacation days per year", "Other benefits"])
    s.heading("5. Confidentiality and Property")
    s.paragraph("The Employee will keep the Employer's confidential information private during and after employment, will use it only for the Employer's business, and will return all property and materials when employment ends. Work product created in the course of employment belongs to the Employer to the extent the law allows.")
    s.heading("6. Termination")
    s.fields(["Notice period by the Employer", "Notice period by the Employee"])
    s.paragraph("Either party may end this Agreement by giving the notice above in writing, or pay in lieu of notice where permitted. The Employer may end employment without notice for serious misconduct. On termination the Employee will be paid all earned wages and any accrued benefits required by law.")
    s.heading("7. General")
    s.paragraph("This Agreement, together with the Employer's written policies, is the entire agreement about the Employee's employment and replaces any earlier offers or discussions. It may be changed only in writing signed by both parties, and is governed by the law of the jurisdiction below.")
    s.fields(["Governing state / country"])
    s.note(REVIEW_NOTE)
    s.heading("Signatures")
    s.signatures(["Employer (authorised signatory)", "Employee"])
    s.fields(["Employer signatory name and title"])


TEMPLATES = {
    "lease-agreement": ("lease-agreement.pdf", "Residential Lease Agreement", lease_agreement),
    "invoice": ("invoice-fillable.pdf", "Invoice", invoice),
    "bill-of-sale": ("bill-of-sale.pdf", "Bill of Sale", bill_of_sale),
    "nda": ("nda.pdf", "Mutual Non-Disclosure Agreement", nda),
    "power-of-attorney": ("power-of-attorney.pdf", "General Power of Attorney", power_of_attorney),
    "liability-waiver": ("liability-waiver.pdf", "Release and Waiver of Liability", liability_waiver),
    "employment-contract": ("employment-contract.pdf", "Employment Agreement", employment_contract),
}


def build(slug, output_dir):
    filename, title, builder = TEMPLATES[slug]
    doc = fitz.open()
    builder(doc)
    doc.set_metadata({"title": title, "producer": "Netkit", "creator": "Netkit fillable forms"})
    path = os.path.join(output_dir, filename)
    doc.save(path, garbage=3, deflate=True)
    doc.close()
    return path


def check(paths):
    for path in paths:
        doc = fitz.open(path)
        per_page = [len(list(page.widgets())) for page in doc]
        print(f"{os.path.basename(path)}: pages={doc.page_count} fields={sum(per_page)} per_page={per_page}")
        doc.close()


def main(argv):
    if len(argv) >= 2 and argv[1] == "--check":
        check(argv[2:])
        return 0
    if len(argv) < 2:
        print(__doc__)
        return 1
    output_dir = argv[1]
    os.makedirs(output_dir, exist_ok=True)
    slugs = argv[2:] or list(TEMPLATES)
    for slug in slugs:
        if slug not in TEMPLATES:
            print(f"Unknown template: {slug}")
            return 1
        path = build(slug, output_dir)
        check([path])
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
