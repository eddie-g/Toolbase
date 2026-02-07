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

            /* ── Template cards ─────────────────────────── */

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
                <h2 style="margin: 0 0 8px;">Guided Invoice Builder</h2>
                <p style="margin: 0 0 24px; font-size: 14px;">Choose a template, then fill out the interactive invoice form in the editor.</p>

                <div class="template-grid" style="grid-template-columns: repeat(2, 1fr);">

                    {{-- Template 1 — Default (Clean) --}}
                    <form action="{{ route('documents.createSimpleInvoice') }}" method="POST" style="margin:0;">
                        @csrf
                        <input type="hidden" name="company_name" value="Your Company Inc.">
                        <input type="hidden" name="company_address" value="1234 Company St.\nCompany Town ST 12345">
                        <input type="hidden" name="customer_name" value="Customer Name">
                        <input type="hidden" name="customer_address" value="1234 Customer St.\nCustomer Town ST 12345">
                        <input type="hidden" name="invoice_number" value="0001001">
                        <input type="hidden" name="invoice_date" value="{{ date('m-d-Y') }}">
                        <input type="hidden" name="due_date" value="{{ date('m-d-Y', strtotime('+14 days')) }}">
                        <input type="hidden" name="terms" value="">
                        <input type="hidden" name="_guided" value="1">
                        <button type="submit" class="tpl-card" style="background:rgba(255,255,255,0.04);font:inherit;color:inherit;padding:0;width:100%;text-align:left;border-radius:14px;">
                            <div class="tpl-preview">
                                <svg viewBox="0 0 300 210" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <rect width="300" height="210" fill="#f8f9fa"/>
                                    {{-- Dark header bar --}}
                                    <rect y="0" width="300" height="3" fill="#1f2937"/>
                                    {{-- Company name --}}
                                    <rect x="24" y="20" width="120" height="10" rx="2" fill="#1f2937"/>
                                    <rect x="24" y="34" width="90" height="5" rx="1" fill="#aaa"/>
                                    {{-- INVOICE label --}}
                                    <text x="210" y="29" font-size="12" fill="#1f2937" font-weight="bold" font-family="sans-serif">INVOICE</text>
                                    {{-- Bill To block --}}
                                    <rect x="24" y="58" width="40" height="5" rx="1" fill="#1f2937"/>
                                    <rect x="24" y="68" width="80" height="6" rx="1" fill="#444"/>
                                    <rect x="24" y="78" width="70" height="4" rx="1" fill="#bbb"/>
                                    {{-- Table header --}}
                                    <rect x="24" y="100" width="252" height="14" rx="2" fill="#1f2937"/>
                                    <text x="30" y="110" font-size="6" fill="white" font-family="sans-serif">QTY</text>
                                    <text x="70" y="110" font-size="6" fill="white" font-family="sans-serif">Description</text>
                                    <text x="200" y="110" font-size="6" fill="white" font-family="sans-serif">Price</text>
                                    <text x="248" y="110" font-size="6" fill="white" font-family="sans-serif">Amount</text>
                                    {{-- Table rows --}}
                                    <rect x="24" y="118" width="252" height="0.5" fill="#e8e8e8"/>
                                    <rect x="30" y="123" width="20" height="5" rx="1" fill="#777"/>
                                    <rect x="70" y="123" width="100" height="5" rx="1" fill="#555"/>
                                    <rect x="248" y="123" width="28" height="5" rx="1" fill="#555"/>
                                    <rect x="24" y="134" width="252" height="0.5" fill="#e8e8e8"/>
                                    {{-- Total --}}
                                    <rect x="200" y="162" width="76" height="1.5" fill="#1f2937"/>
                                    <text x="204" y="176" font-size="7" fill="#1f2937" font-family="sans-serif">Total</text>
                                    <text x="248" y="176" font-size="8" fill="#333" font-weight="bold" font-family="sans-serif">$0.00</text>
                                </svg>
                                <span class="tpl-badge">Guided</span>
                            </div>
                            <div class="tpl-info">
                                <h3>Clean Modern</h3>
                                <p>Dark header, clean layout — guided form</p>
                            </div>
                        </button>
                    </form>

                    {{-- Template 2 — Bold Red (Guided) --}}
                    <form action="{{ route('documents.createSimpleInvoice') }}" method="POST" style="margin:0;">
                        @csrf
                        <input type="hidden" name="company_name" value="Your Company Inc.">
                        <input type="hidden" name="company_address" value="1234 Company St.\nCompany Town ST 12345">
                        <input type="hidden" name="customer_name" value="Customer Name">
                        <input type="hidden" name="customer_address" value="1234 Customer St.\nCustomer Town ST 12345">
                        <input type="hidden" name="invoice_number" value="0001001">
                        <input type="hidden" name="invoice_date" value="{{ date('m-d-Y') }}">
                        <input type="hidden" name="due_date" value="{{ date('m-d-Y', strtotime('+14 days')) }}">
                        <input type="hidden" name="terms" value="">
                        <input type="hidden" name="_guided" value="1">
                        <input type="hidden" name="style" value="bold_red">
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
                                    <text x="30" y="120" font-size="6" fill="white" font-family="sans-serif">QTY</text>
                                    <text x="70" y="120" font-size="6" fill="white" font-family="sans-serif">Description</text>
                                    <text x="210" y="120" font-size="6" fill="white" font-family="sans-serif">Price</text>
                                    <text x="248" y="120" font-size="6" fill="white" font-family="sans-serif">Amount</text>
                                    {{-- Rows with alternating tint --}}
                                    <rect x="24" y="126" width="252" height="14" fill="#fdf0f0"/>
                                    <rect x="30" y="130" width="100" height="5" rx="1" fill="#555"/>
                                    <rect x="248" y="130" width="28" height="5" rx="1" fill="#555"/>
                                    <rect x="30" y="146" width="80" height="5" rx="1" fill="#555"/>
                                    <rect x="248" y="146" width="28" height="5" rx="1" fill="#555"/>
                                    {{-- Total box --}}
                                    <rect x="200" y="178" width="76" height="18" rx="3" fill="#cc3333"/>
                                    <text x="206" y="190" font-size="7" fill="white" font-family="sans-serif">TOTAL</text>
                                    <text x="242" y="190" font-size="8" fill="white" font-weight="bold" font-family="sans-serif">$0.00</text>
                                </svg>
                                <span class="tpl-badge">Guided</span>
                            </div>
                            <div class="tpl-info">
                                <h3>Bold Red</h3>
                                <p>Corporate style with red header — guided form</p>
                            </div>
                        </button>
                    </form>

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


    </body>
</html>
