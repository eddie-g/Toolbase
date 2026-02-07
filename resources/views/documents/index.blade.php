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

            /* ── Guided Invoice Builder (opens in editor) ─── */
            .guided-panel {
                text-align: center;
                padding: 48px 20px;
            }
            .guided-panel svg { margin-bottom: 16px; }
            .guided-panel h3 {
                color: var(--ink);
                margin: 0 0 8px;
                font-size: 20px;
            }
            .guided-panel p {
                color: var(--muted);
                font-size: 14px;
                margin: 0 0 24px;
                max-width: 420px;
                margin-left: auto;
                margin-right: auto;
            }
            .btn-guided {
                background: var(--accent);
                color: #053322;
                font-size: 15px;
                font-weight: 700;
                padding: 14px 32px;
                border-radius: 999px;
                border: none;
                cursor: pointer;
                transition: transform .15s, box-shadow .15s;
                display: inline-flex;
                align-items: center;
                gap: 8px;
            }
            .btn-guided:hover {
                transform: translateY(-1px);
                box-shadow: 0 4px 16px rgba(77,208,168,0.3);
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
                <h2 style="margin: 0 0 16px;">Create from Template</h2>

                <div class="template-tabs">
                    <button class="template-tab" data-tab="guided" type="button">Guided</button>
                    <button class="template-tab active" data-tab="invoice" type="button">Invoice</button>
                    <button class="template-tab" data-tab="newsletter" type="button">Newsletter</button>
                </div>

                {{-- GUIDED TAB --}}
                <div id="tab-guided" class="tab-panel">
                    <div class="guided-panel">
                        <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--accent);">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <line x1="16" y1="13" x2="8" y2="13"/>
                            <line x1="16" y1="17" x2="8" y2="17"/>
                            <polyline points="10 9 9 9 8 9"/>
                        </svg>
                        <h3>Guided Invoice Builder</h3>
                        <p>Fill out an interactive invoice form right inside the editor. Add your company details, line items, and terms &mdash; then save to generate a professional PDF.</p>
                        <form action="{{ route('documents.createSimpleInvoice') }}" method="POST" id="guidedLaunchForm">
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
                            <button type="submit" class="btn-guided">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                                Open Guided Invoice Builder &rarr;
                            </button>
                        </form>
                    </div>
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

            // (Guided invoice builder logic is now in the editor page)
        </script>
    </body>
</html>
