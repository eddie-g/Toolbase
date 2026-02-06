<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>PDF Uploader</title>
        <style>
            :root {
                color-scheme: light;
                --bg: #0b1320;
                --card: #141f2e;
                --ink: #e9f0ff;
                --muted: #a9b7cf;
                --accent: #4dd0a8;
                --danger: #ff6b6b;
            }
            * { box-sizing: border-box; }
            body {
                margin: 0;
                font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
                background: radial-gradient(circle at top, #19283d, var(--bg));
                color: var(--ink);
                min-height: 100vh;
            }
            .shell {
                max-width: 1000px;
                margin: 0 auto;
                padding: 48px 20px 72px;
            }
            h1 {
                margin: 0 0 8px;
                font-size: 32px;
                letter-spacing: 0.5px;
            }
            p { color: var(--muted); }
            .card {
                background: var(--card);
                border: 1px solid rgba(255,255,255,0.08);
                border-radius: 18px;
                padding: 24px;
                margin-top: 24px;
                box-shadow: 0 20px 40px rgba(0,0,0,0.25);
            }
            .upload {
                display: flex;
                gap: 16px;
                flex-wrap: wrap;
                align-items: center;
            }
            input[type="file"] {
                background: #0f1826;
                border: 1px dashed rgba(255,255,255,0.25);
                color: var(--ink);
                padding: 12px;
                border-radius: 10px;
                width: 320px;
            }
            button {
                background: var(--accent);
                border: none;
                color: #053322;
                font-weight: 700;
                padding: 12px 20px;
                border-radius: 999px;
                cursor: pointer;
            }
            .btn-secondary {
                background: transparent;
                border: 1px solid rgba(255,255,255,0.2);
                color: var(--ink);
            }
            .btn-danger {
                background: var(--danger);
                color: #2b0a0a;
            }
            .docs {
                display: grid;
                gap: 16px;
                margin-top: 16px;
            }
            .doc {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 16px;
                border-radius: 14px;
                background: rgba(255,255,255,0.04);
                border: 1px solid rgba(255,255,255,0.08);
            }
            .doc-actions {
                display: inline-flex;
                align-items: center;
                gap: 12px;
            }
            .doc a {
                color: var(--accent);
                text-decoration: none;
                font-weight: 600;
                padding: 8px 16px;
                background: rgba(77, 208, 168, 0.1);
                border-radius: 6px;
                display: inline-block;
            }
            .doc a:hover {
                background: rgba(77, 208, 168, 0.2);
            }
            .doc form {
                margin: 0;
            }
            .tag {
                font-size: 12px;
                color: var(--muted);
            }
            .flash {
                margin-top: 16px;
                color: var(--accent);
                font-weight: 600;
            }
            .error {
                margin-top: 12px;
                color: var(--danger);
                font-weight: 600;
            }

            /* ── Template tabs & cards ─────────────────────────── */
            .template-tabs {
                display: flex;
                gap: 4px;
                margin-bottom: 20px;
            }
            .template-tab {
                background: rgba(255,255,255,0.06);
                border: 1px solid rgba(255,255,255,0.10);
                color: var(--muted);
                font-weight: 600;
                font-size: 14px;
                padding: 10px 24px;
                border-radius: 10px 10px 0 0;
                cursor: pointer;
                transition: all .2s;
            }
            .template-tab:hover {
                background: rgba(255,255,255,0.10);
                color: var(--ink);
            }
            .template-tab.active {
                background: rgba(77,208,168,0.12);
                border-color: var(--accent);
                border-bottom-color: transparent;
                color: var(--accent);
            }
            .tab-panel { display: none; }
            .tab-panel.active { display: block; }

            .template-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 20px;
            }
            @media (max-width: 700px) {
                .template-grid { grid-template-columns: 1fr; }
            }
            .tpl-card {
                background: rgba(255,255,255,0.04);
                border: 1px solid rgba(255,255,255,0.10);
                border-radius: 14px;
                overflow: hidden;
                transition: all .25s;
                cursor: pointer;
                position: relative;
            }
            .tpl-card:hover {
                border-color: var(--accent);
                box-shadow: 0 0 0 1px var(--accent), 0 12px 28px rgba(0,0,0,0.3);
                transform: translateY(-3px);
            }
            .tpl-preview {
                height: 210px;
                display: flex;
                align-items: center;
                justify-content: center;
                position: relative;
                overflow: hidden;
            }
            .tpl-preview svg {
                width: 100%;
                height: 100%;
            }
            .tpl-info {
                padding: 14px 16px;
                border-top: 1px solid rgba(255,255,255,0.06);
            }
            .tpl-info h3 {
                margin: 0 0 4px;
                font-size: 15px;
                color: var(--ink);
            }
            .tpl-info p {
                margin: 0;
                font-size: 12px;
                color: var(--muted);
            }
            .tpl-badge {
                position: absolute;
                top: 10px;
                right: 10px;
                background: rgba(0,0,0,0.55);
                color: var(--accent);
                font-size: 10px;
                font-weight: 700;
                padding: 3px 8px;
                border-radius: 6px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .coming-soon {
                text-align: center;
                padding: 60px 20px;
            }
            .coming-soon svg { margin-bottom: 16px; }
            .coming-soon h3 {
                color: var(--ink);
                margin: 0 0 8px;
            }
            .coming-soon p {
                color: var(--muted);
                font-size: 14px;
                margin: 0;
            }

            /* ── Simple Invoice Builder ─────────────────────── */
            .inv-form { max-width: 700px; }
            .inv-row {
                display: flex;
                gap: 16px;
                margin-bottom: 16px;
            }
            .inv-row > * { flex: 1; }
            .inv-field label {
                display: block;
                font-size: 11px;
                font-weight: 600;
                color: var(--muted);
                margin-bottom: 4px;
                text-transform: uppercase;
                letter-spacing: 0.4px;
            }
            .inv-field input,
            .inv-field textarea {
                width: 100%;
                background: #0f1826;
                border: 1px solid rgba(255,255,255,0.15);
                color: var(--ink);
                padding: 10px 12px;
                border-radius: 8px;
                font-size: 14px;
                font-family: inherit;
                transition: border-color .2s;
            }
            .inv-field input:focus,
            .inv-field textarea:focus {
                outline: none;
                border-color: var(--accent);
            }
            .inv-field textarea {
                resize: vertical;
                min-height: 60px;
            }
            .inv-section-title {
                font-size: 12px;
                font-weight: 700;
                color: var(--accent);
                text-transform: uppercase;
                letter-spacing: 0.6px;
                margin: 24px 0 10px;
                padding-bottom: 6px;
                border-bottom: 1px solid rgba(255,255,255,0.08);
            }
            .inv-section-title:first-child { margin-top: 0; }

            /* Line-items table */
            .li-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 6px;
            }
            .li-table th {
                text-align: left;
                font-size: 11px;
                font-weight: 600;
                color: var(--muted);
                padding: 6px 8px;
                border-bottom: 1px solid rgba(255,255,255,0.10);
                text-transform: uppercase;
                letter-spacing: 0.3px;
            }
            .li-table th:nth-child(1) { width: 60px; }
            .li-table th:nth-child(3) { width: 110px; }
            .li-table th:nth-child(4) { width: 100px; }
            .li-table th:last-child  { width: 40px; }
            .li-table td {
                padding: 4px 4px;
                vertical-align: middle;
            }
            .li-table input {
                width: 100%;
                background: #0f1826;
                border: 1px solid rgba(255,255,255,0.12);
                color: var(--ink);
                padding: 8px 10px;
                border-radius: 6px;
                font-size: 13px;
                font-family: inherit;
            }
            .li-table input:focus {
                outline: none;
                border-color: var(--accent);
            }
            .li-table .amt-cell {
                color: var(--ink);
                font-size: 14px;
                font-weight: 600;
                padding-left: 10px;
                white-space: nowrap;
            }
            .li-remove {
                background: transparent;
                border: none;
                color: var(--danger);
                font-size: 18px;
                cursor: pointer;
                padding: 4px 8px;
                border-radius: 6px;
                line-height: 1;
            }
            .li-remove:hover { background: rgba(255,107,107,0.15); }

            .li-actions {
                display: flex;
                gap: 10px;
                margin-top: 10px;
            }
            .btn-add {
                background: var(--accent);
                color: #053322;
                font-size: 13px;
                font-weight: 700;
                padding: 8px 16px;
                border-radius: 8px;
                border: none;
                cursor: pointer;
            }
            .btn-add-outline {
                background: transparent;
                color: var(--accent);
                font-size: 13px;
                font-weight: 600;
                padding: 8px 16px;
                border-radius: 8px;
                border: 1px solid var(--accent);
                cursor: pointer;
            }
            .btn-add:hover { opacity: 0.9; }
            .btn-add-outline:hover { background: rgba(77,208,168,0.08); }

            .inv-totals {
                display: flex;
                justify-content: flex-end;
                margin-top: 16px;
            }
            .inv-totals-box {
                min-width: 260px;
                border-top: 2px solid var(--accent);
                padding-top: 10px;
            }
            .inv-total-row {
                display: flex;
                justify-content: space-between;
                padding: 4px 0;
                font-size: 14px;
            }
            .inv-total-row.discount { color: var(--danger); }
            .inv-total-row.grand {
                font-size: 18px;
                font-weight: 700;
                border-top: 1px solid rgba(255,255,255,0.12);
                padding-top: 8px;
                margin-top: 4px;
            }
            .inv-total-row .label { color: var(--muted); }
            .inv-total-row.grand .label { color: var(--ink); }

            .inv-submit-row {
                display: flex;
                justify-content: flex-end;
                margin-top: 24px;
                padding-top: 16px;
                border-top: 1px solid rgba(255,255,255,0.08);
            }
            .btn-generate {
                background: var(--accent);
                color: #053322;
                font-size: 15px;
                font-weight: 700;
                padding: 14px 32px;
                border-radius: 999px;
                border: none;
                cursor: pointer;
                transition: transform .15s, box-shadow .15s;
            }
            .btn-generate:hover {
                transform: translateY(-1px);
                box-shadow: 0 4px 16px rgba(77,208,168,0.3);
            }

            .discount-row-container { margin-top: 10px; }
            .discount-fields { display: flex; gap: 12px; }
            .discount-fields .inv-field:first-child { flex: 2; }
            .discount-fields .inv-field:last-child  { flex: 1; }
        </style>
    </head>
    <body>
        <div class="shell">
            <h1>Document Uploader</h1>
            <p>Upload a PDF, then jump into the editor to add text and save the updated file.</p>

            @if (session('status'))
                <div class="flash">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="error">{{ $errors->first() }}</div>
            @endif

            <div class="card">
                <form class="upload" action="{{ route('documents.store') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <input type="file" name="document" accept="application/pdf" required>
                    <button type="submit">Upload PDF</button>
                </form>
            </div>

            <!-- ── Create from Template ──────────────────────────── -->
            <div class="card">
                <h2 style="margin: 0 0 16px;">Create from Template</h2>

                <div class="template-tabs">
                    <button class="template-tab" data-tab="simple" type="button">Simple</button>
                    <button class="template-tab active" data-tab="invoice" type="button">Invoice</button>
                    <button class="template-tab" data-tab="newsletter" type="button">Newsletter</button>
                </div>

                {{-- SIMPLE TAB --}}
                <div id="tab-simple" class="tab-panel">
                    <form class="inv-form" id="simpleInvoiceForm" action="{{ route('documents.createSimpleInvoice') }}" method="POST">
                        @csrf

                        <div class="inv-section-title">Company Details</div>
                        <div class="inv-row">
                            <div class="inv-field">
                                <label>Company Name</label>
                                <input type="text" name="company_name" placeholder="Your Company Inc." value="Your Company Inc.">
                            </div>
                        </div>
                        <div class="inv-row">
                            <div class="inv-field">
                                <label>Company Address</label>
                                <textarea name="company_address" rows="2" placeholder="1234 Company St.&#10;Company Town ST 12345">1234 Company St.\nCompany Town ST 12345</textarea>
                            </div>
                        </div>

                        <div class="inv-section-title">Bill To</div>
                        <div class="inv-row">
                            <div class="inv-field">
                                <label>Customer Name</label>
                                <input type="text" name="customer_name" placeholder="Customer Name" value="Customer Name">
                            </div>
                        </div>
                        <div class="inv-row">
                            <div class="inv-field">
                                <label>Customer Address</label>
                                <textarea name="customer_address" rows="2" placeholder="1234 Customer St.&#10;Customer Town ST 12345">1234 Customer St.\nCustomer Town ST 12345</textarea>
                            </div>
                        </div>

                        <div class="inv-section-title">Invoice Details</div>
                        <div class="inv-row">
                            <div class="inv-field">
                                <label>Invoice #</label>
                                <input type="text" name="invoice_number" placeholder="0001001" value="0001001">
                            </div>
                            <div class="inv-field">
                                <label>Invoice Date</label>
                                <input type="text" name="invoice_date" placeholder="02-06-2026" value="02-06-2026">
                            </div>
                            <div class="inv-field">
                                <label>Due Date</label>
                                <input type="text" name="due_date" placeholder="02-20-2026" value="02-20-2026">
                            </div>
                        </div>

                        <div class="inv-section-title">Line Items</div>
                        <table class="li-table">
                            <thead>
                                <tr>
                                    <th>QTY</th>
                                    <th>Description</th>
                                    <th>Unit Price</th>
                                    <th>Amount</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="lineItemsBody">
                                <tr class="li-row">
                                    <td><input type="number" name="items[0][qty]" class="li-qty" value="1" min="0" step="1"></td>
                                    <td><input type="text"   name="items[0][description]" class="li-desc" placeholder="Service or product"></td>
                                    <td><input type="number" name="items[0][unit_price]" class="li-price" value="0.00" min="0" step="0.01"></td>
                                    <td class="amt-cell">$0.00</td>
                                    <td><button type="button" class="li-remove" title="Remove">&times;</button></td>
                                </tr>
                            </tbody>
                        </table>

                        <div class="li-actions">
                            <button type="button" class="btn-add" id="addLineItem">+ Add Line Item</button>
                            <button type="button" class="btn-add-outline" id="addDiscount">+ Add Discount</button>
                        </div>

                        <div class="discount-row-container" id="discountRow" style="display:none;">
                            <div class="inv-section-title" style="margin-top:16px;">Discount</div>
                            <div class="discount-fields">
                                <div class="inv-field">
                                    <label>Discount Label</label>
                                    <input type="text" name="discount_label" id="discountLabel" placeholder="e.g. 10% Early-pay Discount">
                                </div>
                                <div class="inv-field">
                                    <label>Amount ($)</label>
                                    <input type="number" name="discount_amount" id="discountAmount" value="0" min="0" step="0.01">
                                </div>
                            </div>
                        </div>

                        <div class="inv-totals">
                            <div class="inv-totals-box">
                                <div class="inv-total-row">
                                    <span class="label">Subtotal</span>
                                    <span id="subtotalDisplay">$0.00</span>
                                </div>
                                <div class="inv-total-row discount" id="discountDisplay" style="display:none;">
                                    <span class="label" id="discountLabelDisplay">Discount</span>
                                    <span id="discountAmountDisplay">-$0.00</span>
                                </div>
                                <div class="inv-total-row grand">
                                    <span class="label">Total (USD)</span>
                                    <span id="totalDisplay">$0.00</span>
                                </div>
                            </div>
                        </div>

                        <div class="inv-section-title">Terms &amp; Conditions</div>
                        <div class="inv-row">
                            <div class="inv-field">
                                <textarea name="terms" rows="3" placeholder="Payment instructions, etc."></textarea>
                            </div>
                        </div>

                        <div class="inv-submit-row">
                            <button type="submit" class="btn-generate">Generate Invoice PDF &rarr;</button>
                        </div>
                    </form>
                </div>

                {{-- INVOICE TAB --}}
                <div id="tab-invoice" class="tab-panel active">
                    <div class="template-grid">

                        {{-- Template 1 — Clean Modern --}}
                        <form action="{{ route('documents.createFromTemplate') }}" method="POST" style="margin:0;">
                            @csrf
                            <input type="hidden" name="template" value="clean_modern">
                            <button type="submit" class="tpl-card" style="background:rgba(255,255,255,0.04);font:inherit;color:inherit;padding:0;width:100%;text-align:left;border-radius:14px;">
                                <div class="tpl-preview">
                                    <svg viewBox="0 0 300 210" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <rect width="300" height="210" fill="#f8f9fa"/>
                                        {{-- Blue accent top bar --}}
                                        <rect y="0" width="300" height="3" fill="#3399dd"/>
                                        {{-- Company name --}}
                                        <rect x="24" y="20" width="120" height="10" rx="2" fill="#333"/>
                                        <rect x="24" y="34" width="90" height="5" rx="1" fill="#aaa"/>
                                        {{-- INVOICE label --}}
                                        <rect x="200" y="18" width="76" height="14" rx="2" fill="#3399dd" opacity="0.15"/>
                                        <text x="212" y="29" font-size="10" fill="#3399dd" font-weight="bold" font-family="sans-serif">INVOICE</text>
                                        {{-- Bill To block --}}
                                        <rect x="24" y="58" width="40" height="5" rx="1" fill="#3399dd"/>
                                        <rect x="24" y="68" width="80" height="6" rx="1" fill="#444"/>
                                        <rect x="24" y="78" width="70" height="4" rx="1" fill="#bbb"/>
                                        {{-- Table header --}}
                                        <rect x="24" y="100" width="252" height="14" rx="2" fill="#f0f0f0"/>
                                        <text x="30" y="110" font-size="6" fill="#999" font-family="sans-serif">Description</text>
                                        <text x="180" y="110" font-size="6" fill="#999" font-family="sans-serif">Qty</text>
                                        <text x="210" y="110" font-size="6" fill="#999" font-family="sans-serif">Price</text>
                                        <text x="248" y="110" font-size="6" fill="#999" font-family="sans-serif">Amount</text>
                                        {{-- Table rows --}}
                                        <rect x="24" y="118" width="252" height="0.5" fill="#e8e8e8"/>
                                        <rect x="30" y="123" width="100" height="5" rx="1" fill="#555"/>
                                        <rect x="184" y="123" width="14" height="5" rx="1" fill="#777"/>
                                        <rect x="212" y="123" width="28" height="5" rx="1" fill="#777"/>
                                        <rect x="248" y="123" width="28" height="5" rx="1" fill="#555"/>
                                        <rect x="24" y="134" width="252" height="0.5" fill="#e8e8e8"/>
                                        <rect x="30" y="139" width="80" height="5" rx="1" fill="#555"/>
                                        <rect x="184" y="139" width="14" height="5" rx="1" fill="#777"/>
                                        <rect x="212" y="139" width="28" height="5" rx="1" fill="#777"/>
                                        <rect x="248" y="139" width="28" height="5" rx="1" fill="#555"/>
                                        <rect x="24" y="150" width="252" height="0.5" fill="#e8e8e8"/>
                                        {{-- Total --}}
                                        <rect x="200" y="162" width="76" height="1" fill="#3399dd"/>
                                        <text x="204" y="176" font-size="7" fill="#3399dd" font-family="sans-serif">Total</text>
                                        <text x="248" y="176" font-size="8" fill="#333" font-weight="bold" font-family="sans-serif">$4,710</text>
                                        {{-- Footer --}}
                                        <rect x="24" y="194" width="140" height="4" rx="1" fill="#3399dd" opacity="0.3"/>
                                    </svg>
                                    <span class="tpl-badge">Free</span>
                                </div>
                                <div class="tpl-info">
                                    <h3>Clean Modern</h3>
                                    <p>Minimal layout with blue accent stripe</p>
                                </div>
                            </button>
                        </form>

                        {{-- Template 2 — Bold Red --}}
                        <form action="{{ route('documents.createFromTemplate') }}" method="POST" style="margin:0;">
                            @csrf
                            <input type="hidden" name="template" value="bold_red">
                            <button type="submit" class="tpl-card" style="background:rgba(255,255,255,0.04);font:inherit;color:inherit;padding:0;width:100%;text-align:left;border-radius:14px;">
                                <div class="tpl-preview">
                                    <svg viewBox="0 0 300 210" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <rect width="300" height="210" fill="#f8f9fa"/>
                                        {{-- Red header --}}
                                        <rect y="0" width="300" height="55" fill="#cc3333"/>
                                        <rect x="24" y="16" width="110" height="10" rx="2" fill="white"/>
                                        <rect x="24" y="30" width="160" height="5" rx="1" fill="rgba(255,255,255,0.6)"/>
                                        <text x="210" y="34" font-size="12" fill="white" font-weight="bold" font-family="sans-serif">INVOICE</text>
                                        {{-- Bill To --}}
                                        <rect x="24" y="70" width="32" height="5" rx="1" fill="#cc3333"/>
                                        <rect x="24" y="80" width="80" height="6" rx="1" fill="#444"/>
                                        <rect x="24" y="90" width="70" height="4" rx="1" fill="#bbb"/>
                                        {{-- Table header --}}
                                        <rect x="24" y="110" width="252" height="14" rx="2" fill="#cc3333"/>
                                        <text x="30" y="120" font-size="6" fill="white" font-family="sans-serif">Description</text>
                                        <text x="180" y="120" font-size="6" fill="white" font-family="sans-serif">Qty</text>
                                        <text x="210" y="120" font-size="6" fill="white" font-family="sans-serif">Price</text>
                                        <text x="248" y="120" font-size="6" fill="white" font-family="sans-serif">Amount</text>
                                        {{-- Rows with alternating tint --}}
                                        <rect x="24" y="126" width="252" height="14" fill="#fdf0f0"/>
                                        <rect x="30" y="130" width="100" height="5" rx="1" fill="#555"/>
                                        <rect x="248" y="130" width="28" height="5" rx="1" fill="#555"/>
                                        <rect x="30" y="146" width="80" height="5" rx="1" fill="#555"/>
                                        <rect x="248" y="146" width="28" height="5" rx="1" fill="#555"/>
                                        <rect x="24" y="154" width="252" height="14" fill="#fdf0f0"/>
                                        <rect x="30" y="158" width="90" height="5" rx="1" fill="#555"/>
                                        <rect x="248" y="158" width="28" height="5" rx="1" fill="#555"/>
                                        {{-- Total box --}}
                                        <rect x="200" y="178" width="76" height="18" rx="3" fill="#cc3333"/>
                                        <text x="206" y="190" font-size="7" fill="white" font-family="sans-serif">TOTAL</text>
                                        <text x="242" y="190" font-size="8" fill="white" font-weight="bold" font-family="sans-serif">$4,710</text>
                                    </svg>
                                    <span class="tpl-badge">Free</span>
                                </div>
                                <div class="tpl-info">
                                    <h3>Bold Red</h3>
                                    <p>Corporate style with red header block</p>
                                </div>
                            </button>
                        </form>

                        {{-- Template 3 — Classic Blue --}}
                        <form action="{{ route('documents.createFromTemplate') }}" method="POST" style="margin:0;">
                            @csrf
                            <input type="hidden" name="template" value="classic_blue">
                            <button type="submit" class="tpl-card" style="background:rgba(255,255,255,0.04);font:inherit;color:inherit;padding:0;width:100%;text-align:left;border-radius:14px;">
                                <div class="tpl-preview">
                                    <svg viewBox="0 0 300 210" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <rect width="300" height="210" fill="#f8f9fc"/>
                                        {{-- Side stripe --}}
                                        <rect x="0" y="0" width="4" height="210" fill="#1a2e5c"/>
                                        {{-- Gold rules --}}
                                        <rect x="24" y="14" width="252" height="1.5" fill="#c79e33"/>
                                        {{-- Company name --}}
                                        <rect x="24" y="24" width="110" height="10" rx="2" fill="#1a2e5c"/>
                                        <rect x="24" y="38" width="150" height="4" rx="1" fill="#aaa"/>
                                        {{-- INVOICE label --}}
                                        <text x="210" y="33" font-size="12" fill="#1a2e5c" font-weight="bold" font-family="sans-serif">INVOICE</text>
                                        {{-- Gold divider --}}
                                        <rect x="24" y="52" width="252" height="0.75" fill="#c79e33"/>
                                        {{-- Bill To --}}
                                        <rect x="24" y="62" width="32" height="5" rx="1" fill="#c79e33"/>
                                        <rect x="24" y="72" width="80" height="6" rx="1" fill="#333"/>
                                        <rect x="24" y="82" width="70" height="4" rx="1" fill="#bbb"/>
                                        {{-- Table header --}}
                                        <rect x="24" y="100" width="252" height="14" rx="2" fill="#1a2e5c"/>
                                        <text x="30" y="110" font-size="6" fill="white" font-family="sans-serif">Description</text>
                                        <text x="180" y="110" font-size="6" fill="white" font-family="sans-serif">Qty</text>
                                        <text x="210" y="110" font-size="6" fill="white" font-family="sans-serif">Rate</text>
                                        <text x="248" y="110" font-size="6" fill="white" font-family="sans-serif">Amount</text>
                                        {{-- Rows --}}
                                        <rect x="24" y="116" width="252" height="14" fill="#eef1f7"/>
                                        <rect x="30" y="120" width="100" height="5" rx="1" fill="#444"/>
                                        <rect x="248" y="120" width="28" height="5" rx="1" fill="#444"/>
                                        <rect x="30" y="136" width="80" height="5" rx="1" fill="#444"/>
                                        <rect x="248" y="136" width="28" height="5" rx="1" fill="#444"/>
                                        <rect x="24" y="144" width="252" height="14" fill="#eef1f7"/>
                                        <rect x="30" y="148" width="90" height="5" rx="1" fill="#444"/>
                                        <rect x="248" y="148" width="28" height="5" rx="1" fill="#444"/>
                                        {{-- Total box with gold border --}}
                                        <rect x="200" y="172" width="76" height="18" rx="3" fill="#1a2e5c" stroke="#c79e33" stroke-width="1.5"/>
                                        <text x="206" y="184" font-size="7" fill="#c79e33" font-family="sans-serif">TOTAL</text>
                                        <text x="242" y="184" font-size="8" fill="white" font-weight="bold" font-family="sans-serif">$4,710</text>
                                        {{-- Bottom gold rule --}}
                                        <rect x="24" y="200" width="252" height="0.75" fill="#c79e33"/>
                                    </svg>
                                    <span class="tpl-badge">Free</span>
                                </div>
                                <div class="tpl-info">
                                    <h3>Classic Blue</h3>
                                    <p>Elegant navy &amp; gold with side stripe</p>
                                </div>
                            </button>
                        </form>

                    </div>
                </div>

                {{-- NEWSLETTER TAB --}}
                <div id="tab-newsletter" class="tab-panel">
                    <div class="coming-soon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--muted);">
                            <rect x="2" y="4" width="20" height="16" rx="2"/>
                            <path d="M2 8l10 6 10-6"/>
                        </svg>
                        <h3>Newsletter Templates</h3>
                        <p>Coming soon — beautiful newsletter layouts are on the way.</p>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2 style="margin: 0 0 12px;">Your PDFs</h2>
                <div class="docs">
                    @forelse ($documents as $document)
                        <div class="doc">
                            <div>
                                <div>{{ $document->original_name }}</div>
                                <div class="tag">{{ number_format($document->size_bytes / 1024, 1) }} KB</div>
                            </div>
                            <div class="doc-actions">
                                <a href="{{ route('documents.edit', $document) }}">Edit</a>
                                <form action="{{ route('documents.destroy', $document) }}" method="POST">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn-danger" type="submit" onclick="return confirm('Delete this document?')">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <div class="tag">No uploads yet.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <script>
            // ── Tab switching ─────────────────────────────────────
            document.querySelectorAll('.template-tab').forEach(tab => {
                tab.addEventListener('click', () => {
                    document.querySelectorAll('.template-tab').forEach(t => t.classList.remove('active'));
                    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
                    tab.classList.add('active');
                    document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
                });
            });

            // ── Simple Invoice Builder JS ─────────────────────────
            (function() {
                const tbody = document.getElementById('lineItemsBody');
                const addBtn = document.getElementById('addLineItem');
                const addDiscountBtn = document.getElementById('addDiscount');
                const discountRow = document.getElementById('discountRow');
                const discountLabel = document.getElementById('discountLabel');
                const discountAmount = document.getElementById('discountAmount');
                const subtotalEl = document.getElementById('subtotalDisplay');
                const discountDisplay = document.getElementById('discountDisplay');
                const discountLabelDisplay = document.getElementById('discountLabelDisplay');
                const discountAmountDisplay = document.getElementById('discountAmountDisplay');
                const totalEl = document.getElementById('totalDisplay');
                let rowIndex = 1;

                function fmt(n) {
                    return '$' + Math.max(0, n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                }

                function recalc() {
                    let subtotal = 0;
                    tbody.querySelectorAll('.li-row').forEach(row => {
                        const qty = parseFloat(row.querySelector('.li-qty').value) || 0;
                        const price = parseFloat(row.querySelector('.li-price').value) || 0;
                        const amount = qty * price;
                        row.querySelector('.amt-cell').textContent = fmt(amount);
                        subtotal += amount;
                    });

                    subtotalEl.textContent = fmt(subtotal);

                    const disc = parseFloat(discountAmount.value) || 0;
                    const dLabel = discountLabel.value.trim();
                    if (disc > 0 && discountRow.style.display !== 'none') {
                        discountDisplay.style.display = 'flex';
                        discountLabelDisplay.textContent = dLabel || 'Discount';
                        discountAmountDisplay.textContent = '-' + fmt(disc);
                    } else {
                        discountDisplay.style.display = 'none';
                    }

                    const total = Math.max(0, subtotal - (discountRow.style.display !== 'none' ? disc : 0));
                    totalEl.textContent = fmt(total);
                }

                function attachRowEvents(row) {
                    row.querySelector('.li-qty').addEventListener('input', recalc);
                    row.querySelector('.li-price').addEventListener('input', recalc);
                    row.querySelector('.li-remove').addEventListener('click', () => {
                        if (tbody.querySelectorAll('.li-row').length > 1) {
                            row.remove();
                            reindex();
                            recalc();
                        }
                    });
                }

                function reindex() {
                    tbody.querySelectorAll('.li-row').forEach((row, i) => {
                        row.querySelector('.li-qty').name  = `items[${i}][qty]`;
                        row.querySelector('.li-desc').name = `items[${i}][description]`;
                        row.querySelector('.li-price').name = `items[${i}][unit_price]`;
                    });
                    rowIndex = tbody.querySelectorAll('.li-row').length;
                }

                // Attach events to the initial row
                tbody.querySelectorAll('.li-row').forEach(r => attachRowEvents(r));

                addBtn.addEventListener('click', () => {
                    const tr = document.createElement('tr');
                    tr.className = 'li-row';
                    tr.innerHTML = `
                        <td><input type="number" name="items[${rowIndex}][qty]" class="li-qty" value="1" min="0" step="1"></td>
                        <td><input type="text"   name="items[${rowIndex}][description]" class="li-desc" placeholder="Service or product"></td>
                        <td><input type="number" name="items[${rowIndex}][unit_price]" class="li-price" value="0.00" min="0" step="0.01"></td>
                        <td class="amt-cell">$0.00</td>
                        <td><button type="button" class="li-remove" title="Remove">&times;</button></td>
                    `;
                    tbody.appendChild(tr);
                    attachRowEvents(tr);
                    rowIndex++;
                    tr.querySelector('.li-desc').focus();
                });

                addDiscountBtn.addEventListener('click', () => {
                    const show = discountRow.style.display === 'none';
                    discountRow.style.display = show ? 'block' : 'none';
                    addDiscountBtn.textContent = show ? '− Remove Discount' : '+ Add Discount';
                    if (!show) {
                        discountAmount.value = 0;
                        discountLabel.value = '';
                    }
                    recalc();
                });

                discountAmount.addEventListener('input', recalc);
                discountLabel.addEventListener('input', recalc);

                recalc();
            })();
        </script>
    </body>
</html>
