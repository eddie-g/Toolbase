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

                <div class="template-grid" style="grid-template-columns: repeat({{ min(count($guidedTemplates), 3) }}, 1fr);">
                    @foreach ($guidedTemplates as $tpl)
                    <form action="{{ route('documents.createSimpleInvoice') }}" method="POST" style="margin:0;">
                        @csrf
                        @php $defaults = $tpl->defaults ?? []; @endphp
                        <input type="hidden" name="company_name"     value="{{ $defaults['company_name'] ?? 'Your Company Inc.' }}">
                        <input type="hidden" name="company_address"  value="{{ $defaults['company_address'] ?? '' }}">
                        <input type="hidden" name="customer_name"    value="{{ $defaults['customer_name'] ?? 'Customer Name' }}">
                        <input type="hidden" name="customer_address" value="{{ $defaults['customer_address'] ?? '' }}">
                        <input type="hidden" name="invoice_number"   value="{{ $defaults['invoice_number'] ?? '0001001' }}">
                        <input type="hidden" name="invoice_date"     value="{{ date('m-d-Y') }}">
                        <input type="hidden" name="due_date"         value="{{ date('m-d-Y', strtotime('+14 days')) }}">
                        <input type="hidden" name="terms"            value="{{ $defaults['terms'] ?? '' }}">
                        <input type="hidden" name="_guided"          value="1">
                        @if ($tpl->slug !== 'default')
                        <input type="hidden" name="style" value="{{ $tpl->slug }}">
                        @endif
                        <button type="submit" class="tpl-card" style="background:rgba(255,255,255,0.04);font:inherit;color:inherit;padding:0;width:100%;text-align:left;border-radius:14px;">
                            <div class="tpl-preview">
                                {!! $tpl->preview_html !!}
                                <span class="tpl-badge">Guided</span>
                            </div>
                            <div class="tpl-info">
                                <h3>{{ $tpl->name }}</h3>
                                <p>{{ $tpl->description }}</p>
                            </div>
                        </button>
                    </form>
                    @endforeach
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
