<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>PDF Uploader</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            :root {
                --accent: var(--color-emerald-400);
                --accent-strong: var(--color-emerald-300);
                --ink: var(--color-gray-900);
                --muted: var(--color-gray-600);
                --danger: var(--color-red-500);
                --surface-bg: color-mix(in oklab, var(--color-white) 92%, transparent);
                --surface-border: color-mix(in oklab, var(--color-gray-200) 85%, transparent);
                --soft-bg: color-mix(in oklab, var(--color-gray-100) 85%, transparent);
                --soft-bg-hover: color-mix(in oklab, var(--color-gray-200) 65%, transparent);
                --dropzone-bg: color-mix(in oklab, var(--color-gray-100) 75%, transparent);
                --dropzone-accent-bg: color-mix(in oklab, var(--accent) 16%, transparent);
                --tab-count-inactive-bg: color-mix(in oklab, var(--color-gray-200) 80%, transparent);
                --tab-count-active-bg: color-mix(in oklab, var(--accent) 16%, transparent);
                --callout-bg: linear-gradient(
                    135deg,
                    color-mix(in oklab, var(--accent) 12%, transparent) 0%,
                    color-mix(in oklab, var(--color-gray-900) 40%, transparent) 100%
                );
                --callout-border: color-mix(in oklab, var(--accent) 35%, transparent);
                --guided-pill-bg: color-mix(in oklab, var(--accent) 18%, transparent);
                --ai-pill-bg: color-mix(in oklab, var(--color-violet-500) 22%, transparent);
                --ai-pill-text: var(--color-violet-700);
            }
            .dark {
                --ink: var(--color-gray-100);
                --muted: var(--color-gray-400);
                --danger: var(--color-red-400);
                --surface-bg: color-mix(in oklab, var(--color-gray-900) 70%, transparent);
                --surface-border: color-mix(in oklab, var(--color-gray-700) 70%, transparent);
                --soft-bg: color-mix(in oklab, var(--color-gray-800) 55%, transparent);
                --soft-bg-hover: color-mix(in oklab, var(--color-gray-700) 60%, transparent);
                --dropzone-bg: color-mix(in oklab, var(--color-gray-800) 55%, transparent);
                --dropzone-accent-bg: color-mix(in oklab, var(--accent) 22%, transparent);
                --tab-count-inactive-bg: color-mix(in oklab, var(--color-gray-700) 70%, transparent);
                --tab-count-active-bg: color-mix(in oklab, var(--accent) 22%, transparent);
                --callout-bg: linear-gradient(
                    135deg,
                    color-mix(in oklab, var(--accent) 10%, transparent) 0%,
                    color-mix(in oklab, var(--color-gray-900) 65%, transparent) 100%
                );
                --callout-border: color-mix(in oklab, var(--accent) 45%, transparent);
                --guided-pill-bg: color-mix(in oklab, var(--accent) 20%, transparent);
                --ai-pill-bg: color-mix(in oklab, var(--color-violet-400) 20%, transparent);
                --ai-pill-text: var(--color-violet-300);
            }
            body {
                margin: 0;
            }
            .uploader-page {
                padding: 28px 16px 40px;
            }
            .page-container {
                max-width: 1200px;
                margin: 0 auto;
            }
            .shell {
                display: grid;
                gap: 18px;
            }
            .shell > h1 {
                margin: 0;
                font-size: clamp(1.5rem, 2.6vw, 2.1rem);
                line-height: 1.2;
            }
            .shell > p {
                margin: -8px 0 4px;
                color: var(--muted);
            }
            .card {
                background: var(--surface-bg);
                border: 1px solid var(--surface-border);
                border-radius: 16px;
                padding: 20px;
                backdrop-filter: blur(6px);
            }
            .upload {
                display: grid;
                gap: 14px;
            }
            .upload-input {
                display: none;
            }
            .upload-dropzone {
                min-height: 230px;
                border: 2px dashed var(--surface-border);
                border-radius: 10px;
                background: var(--dropzone-bg);
                display: grid;
                place-items: center;
                text-align: center;
                padding: 24px;
                cursor: pointer;
                transition: border-color .2s, background-color .2s, transform .2s;
                outline: none;
            }
            .upload-dropzone:hover,
            .upload-dropzone:focus {
                border-color: var(--accent);
                background: var(--dropzone-accent-bg);
                transform: translateY(-1px);
            }
            .upload-dropzone.dragover {
                border-color: var(--accent);
                background: var(--dropzone-accent-bg);
            }
            .upload-dropzone strong {
                display: block;
                font-size: 24px;
                line-height: 1.25;
                margin-bottom: 8px;
            }
            .upload-dropzone span {
                color: var(--muted);
                font-size: 14px;
            }
            .upload-meta {
                display: flex;
                gap: 12px;
                align-items: center;
                flex-wrap: wrap;
            }
            .upload-file-name {
                padding: 10px 12px;
                border-radius: 8px;
                border: 1px solid var(--surface-border);
                background: var(--soft-bg);
                color: var(--muted);
                min-width: 260px;
                max-width: 100%;
                white-space: nowrap;
                text-overflow: ellipsis;
                overflow: hidden;
                flex: 1;
            }
            .upload-error {
                color: var(--danger);
                font-size: 14px;
                font-weight: 600;
            }
            .upload-progress {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .upload-progress-track {
                height: 10px;
                border-radius: 99px;
                background: var(--soft-bg);
                border: 1px solid var(--surface-border);
                overflow: hidden;
                flex: 1;
                min-width: 220px;
            }
            .upload-progress-bar {
                width: 0%;
                height: 100%;
                background: linear-gradient(90deg, var(--accent), var(--accent-strong));
                transition: width .12s linear;
            }
            .upload-progress-value {
                font-variant-numeric: tabular-nums;
                min-width: 42px;
                text-align: right;
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
                background: var(--soft-bg);
                border: 1px solid var(--surface-border);
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
                background: var(--dropzone-accent-bg);
                border-radius: 6px;
                display: inline-block;
            }
            .doc a:hover {
                background: var(--tab-count-active-bg);
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
            .limit-modal {
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.55);
                display: none;
                align-items: center;
                justify-content: center;
                z-index: 100000;
                padding: 20px;
            }
            .limit-modal-card {
                width: min(520px, 95vw);
                background: var(--surface-bg);
                border: 1px solid var(--surface-border);
                border-radius: 14px;
                padding: 22px;
                box-shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
            }
            .limit-modal-title {
                margin: 0 0 10px;
                font-size: 22px;
                line-height: 1.2;
            }
            .limit-modal-copy {
                margin: 0 0 18px;
                color: var(--muted);
                line-height: 1.5;
            }
            .limit-modal-actions {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
            }
            .limit-modal-actions a {
                text-decoration: none;
            }

            /* ── Template cards ─────────────────────────── */

            .template-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 20px;
            }
            @media (max-width: 700px) {
                .template-grid { grid-template-columns: 1fr; }
                .upload-dropzone {
                    min-height: 180px;
                }
            }
            .tpl-card {
                background: var(--soft-bg);
                border: 1px solid var(--surface-border);
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
                border-top: 1px solid var(--surface-border);
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
                background: color-mix(in oklab, var(--color-black) 55%, transparent);
                color: var(--accent);
                font-size: 10px;
                font-weight: 700;
                padding: 3px 8px;
                border-radius: 6px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .tpl-tab-btn:hover {
                color: var(--ink) !important;
            }
            .tpl-tab-btn.active:hover {
                color: var(--accent) !important;
            }

        </style>
    </head>
    <body class="bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 min-h-screen antialiased">
        <x-site-header />

        <main class="uploader-page">
            <div class="page-container">
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
                <form class="upload" id="upload-form" action="{{ route('documents.store') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <input id="document-input" class="upload-input" type="file" name="document" accept="application/pdf,.pdf" required>

                    <div id="upload-dropzone" class="upload-dropzone" role="button" tabindex="0" aria-label="Upload PDF by click or drag and drop">
                        <div>
                            <strong>Drop your PDF here</strong>
                            <span>or click to browse your files</span>
                            <div class="tag" style="margin-top: 10px;">PDF files only</div>
                        </div>
                    </div>

                    <div class="upload-meta">
                        <div id="upload-file-name" class="upload-file-name">No file selected</div>
                        <button id="upload-submit" type="submit">Upload PDF</button>
                    </div>

                    <div id="upload-error" class="upload-error" style="display:none;"></div>

                    <div id="upload-progress" class="upload-progress" style="display:none;" aria-live="polite">
                        <div class="upload-progress-track">
                            <div id="upload-progress-bar" class="upload-progress-bar"></div>
                        </div>
                        <div id="upload-progress-value" class="upload-progress-value">0%</div>
                    </div>
                </form>
            </div>

            <!-- ── Blank PDF ────────────────────────────── -->
                    <div class="card">
                        <h2 style="margin: 0 0 6px;">Start with an Empty PDF</h2>
                        <p style="margin: 0 0 16px; font-size: 14px; color: var(--muted);">Create a blank page to add text, images, and annotations from scratch.</p>
                        <form action="{{ route('documents.createBlank') }}" method="POST" style="display:flex; flex-wrap:wrap; align-items:flex-end; gap:14px;">
                            @csrf
                            <div style="display:flex; flex-direction:column; gap:4px;">
                                <label for="blank-page-size" style="font-size:12px; color:var(--muted); font-weight:600;">Page size</label>
                                <select id="blank-page-size" name="page_size" style="background:var(--soft-bg); border:1px solid var(--surface-border); color:var(--ink); border-radius:8px; padding:9px 12px; font:inherit; font-size:14px; cursor:pointer;">
                                    <option value="A4">A4</option>
                                    <option value="Letter" selected>Letter</option>
                                    <option value="Legal">Legal</option>
                                    <option value="A3">A3</option>
                                    <option value="A5">A5</option>
                                </select>
                            </div>
                            <div style="display:flex; flex-direction:column; gap:4px;">
                                <label for="blank-orientation" style="font-size:12px; color:var(--muted); font-weight:600;">Orientation</label>
                                <select id="blank-orientation" name="orientation" style="background:var(--soft-bg); border:1px solid var(--surface-border); color:var(--ink); border-radius:8px; padding:9px 12px; font:inherit; font-size:14px; cursor:pointer;">
                                    <option value="portrait" selected>Portrait</option>
                                    <option value="landscape">Landscape</option>
                                </select>
                            </div>
                            <button type="submit" class="btn-secondary" style="display:inline-flex; align-items:center; gap:8px; padding:10px 20px;">
                                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="12" x2="12" y2="18"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                                Create Blank PDF
                            </button>
                        </form>
                    </div>

            <!-- ── Guided Templates ────────────────────────────── -->
                    <div class="card">
                <h2 style="margin: 0 0 8px;">Guided Templates</h2>
                <p style="margin: 0 0 20px; font-size: 14px;">Choose a template to get started — fill out the interactive form in the editor.</p>

                <!-- Category Tabs -->
                <div class="tpl-tabs" style="display:flex; gap:0; margin-bottom:24px; border-bottom:1px solid rgba(255,255,255,0.1);">
                    @php
                        $categories = [
                            'invoice'    => ['label' => 'Invoice',    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/>'],
                            'newsletter' => ['label' => 'Newsletter', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/>'],
                            'business'   => ['label' => 'Business',   'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>'],
                            'realestate' => ['label' => 'Real Estate', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>'],
                        ];
                    @endphp
                    @foreach ($categories as $catKey => $cat)
                        <button type="button"
                            class="tpl-tab-btn{{ $loop->first ? ' active' : '' }}"
                            data-category="{{ $catKey }}"
                            onclick="switchTemplateCategory('{{ $catKey }}')"
                            style="background:none; border:none; color:{{ $loop->first ? 'var(--accent)' : 'var(--muted)' }}; font:inherit; font-size:14px; font-weight:600; padding:10px 18px; cursor:pointer; border-bottom:2px solid {{ $loop->first ? 'var(--accent)' : 'transparent' }}; display:flex; align-items:center; gap:6px; transition:all .2s;">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $cat['icon'] !!}</svg>
                            {{ $cat['label'] }}
                            <span style="background:{{ $loop->first ? 'var(--tab-count-active-bg)' : 'var(--tab-count-inactive-bg)' }}; font-size:11px; padding:1px 7px; border-radius:10px; font-weight:700;">{{ ($guidedTemplatesByType[$catKey] ?? collect())->count() }}</span>
                        </button>
                    @endforeach
                </div>

                <!-- Template Grids (one per category) -->
                @foreach ($categories as $catKey => $cat)
                <div class="tpl-category-grid" id="tpl-grid-{{ $catKey }}" style="{{ $loop->first ? '' : 'display:none;' }}">
                    <div class="template-grid" style="grid-template-columns: repeat({{ min(($guidedTemplatesByType[$catKey] ?? collect())->count(), 3) }}, 1fr);">
                        @foreach ($guidedTemplatesByType[$catKey] ?? [] as $tpl)
                        <form action="{{ $tpl->type === 'invoice' ? route('documents.createSimpleInvoice') : route('documents.createFromGuidedTemplate') }}" method="POST" style="margin:0;">
                            @csrf
                            @php $defaults = $tpl->defaults ?? []; @endphp
                            @if ($tpl->type === 'invoice')
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
                            @else
                                <input type="hidden" name="_template_type"   value="{{ $tpl->type }}">
                                <input type="hidden" name="_template_slug"   value="{{ $tpl->slug }}">
                                <input type="hidden" name="_guided"          value="1">
                            @endif
                            <button type="submit" class="tpl-card" style="background:var(--soft-bg);font:inherit;color:inherit;padding:0;width:100%;text-align:left;border-radius:14px;">
                                <div class="tpl-preview">
                                    {!! $tpl->preview_html !!}
                                    <span class="tpl-badge">{{ $cat['label'] }}</span>
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
                @endforeach
            </div>

            <script>
                function shouldShowUploadLimitModal(message) {
                    if (!message) return false;
                    const text = String(message).toLowerCase();
                    return text.includes('monthly pdf upload limit reached') || text.includes('out of pdf uploads');
                }

                function showUploadLimitModal() {
                    const modal = document.getElementById('pdf-upload-limit-modal');
                    if (!modal) return;
                    modal.style.display = 'flex';
                    modal.setAttribute('aria-hidden', 'false');
                }

                function hideUploadLimitModal() {
                    const modal = document.getElementById('pdf-upload-limit-modal');
                    if (!modal) return;
                    modal.style.display = 'none';
                    modal.setAttribute('aria-hidden', 'true');
                }

                function switchTemplateCategory(category) {
                    // Hide all grids
                    document.querySelectorAll('.tpl-category-grid').forEach(g => g.style.display = 'none');
                    // Show selected
                    const grid = document.getElementById('tpl-grid-' + category);
                    if (grid) grid.style.display = '';
                    // Update tab buttons
                    document.querySelectorAll('.tpl-tab-btn').forEach(btn => {
                        const isActive = btn.dataset.category === category;
                        btn.classList.toggle('active', isActive);
                        btn.style.color = isActive ? 'var(--accent)' : 'var(--muted)';
                        btn.style.borderBottomColor = isActive ? 'var(--accent)' : 'transparent';
                        btn.querySelector('span').style.background = isActive ? 'var(--tab-count-active-bg)' : 'var(--tab-count-inactive-bg)';
                    });
                }

                function updateBulkState() {
                    const checkboxes = document.querySelectorAll('.doc-checkbox:checked');
                    const allCheckboxes = document.querySelectorAll('.doc-checkbox');
                    const btn = document.getElementById('bulk-delete-btn');
                    const countSpan = document.getElementById('selected-count');
                    const selectAll = document.getElementById('select-all-checkbox');
                    
                    if (checkboxes.length > 0) {
                        btn.style.display = 'inline-block';
                        countSpan.textContent = checkboxes.length;
                    } else {
                        btn.style.display = 'none';
                    }

                    // Update "Select All" checkbox state
                    if (selectAll) {
                        selectAll.checked = checkboxes.length > 0 && checkboxes.length === allCheckboxes.length;
                        selectAll.indeterminate = checkboxes.length > 0 && checkboxes.length < allCheckboxes.length;
                    }
                }

                function toggleSelectAll(selectAllCheckbox) {
                    const checkboxes = document.querySelectorAll('.doc-checkbox');
                    checkboxes.forEach(cb => {
                        cb.checked = selectAllCheckbox.checked;
                    });
                    updateBulkState();
                }

                function submitBulkDelete() {
                    if (!confirm('Are you sure you want to delete the selected documents?')) return;
                    
                    const form = document.getElementById('bulk-delete-form');
                    const checkboxes = document.querySelectorAll('.doc-checkbox:checked');
                    
                    // Clear existing inputs if any (unexpected but safe)
                    form.innerHTML = '@csrf';
                    
                    checkboxes.forEach(cb => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'ids[]';
                        input.value = cb.value;
                        form.appendChild(input);
                    });
                    
                    form.submit();
                }

                function initUploadDropzone() {
                    const form = document.getElementById('upload-form');
                    const input = document.getElementById('document-input');
                    const dropzone = document.getElementById('upload-dropzone');
                    const fileName = document.getElementById('upload-file-name');
                    const error = document.getElementById('upload-error');
                    const progress = document.getElementById('upload-progress');
                    const progressBar = document.getElementById('upload-progress-bar');
                    const progressValue = document.getElementById('upload-progress-value');
                    const submitBtn = document.getElementById('upload-submit');

                    if (!form || !input || !dropzone || !fileName || !error || !progress || !progressBar || !progressValue || !submitBtn) {
                        return;
                    }

                    let selectedFile = null;

                    const isPdf = (file) => {
                        if (!file) return false;
                        const mime = (file.type || '').toLowerCase();
                        const name = (file.name || '').toLowerCase();
                        return mime === 'application/pdf' || name.endsWith('.pdf');
                    };

                    const formatBytes = (bytes) => {
                        if (!bytes || bytes < 1024) return `${bytes || 0} B`;
                        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
                        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
                    };

                    const showError = (message) => {
                        error.textContent = message;
                        error.style.display = 'block';
                    };

                    const clearError = () => {
                        error.textContent = '';
                        error.style.display = 'none';
                    };

                    const setProgress = (percent) => {
                        const value = Math.max(0, Math.min(100, Math.round(percent)));
                        progressBar.style.width = `${value}%`;
                        progressValue.textContent = `${value}%`;
                    };

                    const setFile = (file) => {
                        if (!file) return;
                        if (!isPdf(file)) {
                            selectedFile = null;
                            input.value = '';
                            fileName.textContent = 'No file selected';
                            showError('Only PDF files are allowed.');
                            return;
                        }

                        clearError();
                        selectedFile = file;
                        fileName.textContent = `${file.name} (${formatBytes(file.size)})`;

                        try {
                            const transfer = new DataTransfer();
                            transfer.items.add(file);
                            input.files = transfer.files;
                        } catch (_) {}
                    };

                    dropzone.addEventListener('click', () => input.click());
                    dropzone.addEventListener('keydown', (event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            input.click();
                        }
                    });

                    input.addEventListener('change', () => setFile(input.files[0]));

                    ['dragenter', 'dragover'].forEach((eventName) => {
                        dropzone.addEventListener(eventName, (event) => {
                            event.preventDefault();
                            dropzone.classList.add('dragover');
                        });
                    });

                    ['dragleave', 'drop'].forEach((eventName) => {
                        dropzone.addEventListener(eventName, (event) => {
                            event.preventDefault();
                            dropzone.classList.remove('dragover');
                        });
                    });

                    dropzone.addEventListener('drop', (event) => {
                        const file = event.dataTransfer?.files?.[0];
                        setFile(file);
                    });

                    form.addEventListener('submit', (event) => {
                        event.preventDefault();
                        clearError();

                        const file = selectedFile || input.files[0];
                        if (!file) {
                            showError('Please choose a PDF file before uploading.');
                            return;
                        }
                        if (!isPdf(file)) {
                            showError('Only PDF files are allowed.');
                            return;
                        }

                        const data = new FormData(form);
                        data.set('document', file);

                        submitBtn.disabled = true;
                        progress.style.display = 'flex';
                        setProgress(0);

                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', form.action, true);
                        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                        xhr.upload.addEventListener('progress', (uploadEvent) => {
                            if (!uploadEvent.lengthComputable) return;
                            setProgress((uploadEvent.loaded / uploadEvent.total) * 100);
                        });

                        xhr.addEventListener('load', () => {
                            if (xhr.status >= 200 && xhr.status < 300) {
                                setProgress(100);
                                window.location.href = xhr.responseURL || window.location.href;
                                return;
                            }

                            submitBtn.disabled = false;
                            progress.style.display = 'none';
                            let message = 'Upload failed. Please try again.';
                            try {
                                const body = JSON.parse(xhr.responseText);
                                message = body.errors?.document?.[0] || body.message || message;
                            } catch (_) {}
                            if (shouldShowUploadLimitModal(message)) {
                                showUploadLimitModal();
                            }
                            showError(message);
                        });

                        xhr.addEventListener('error', () => {
                            submitBtn.disabled = false;
                            progress.style.display = 'none';
                            showError('Network error while uploading. Please try again.');
                        });

                        xhr.send(data);
                    });
                }

                // Initial check in case browser restores checked state on reload
                document.addEventListener('DOMContentLoaded', () => {
                    updateBulkState();
                    initUploadDropzone();

                    const closeBtn = document.getElementById('pdf-upload-limit-close');
                    const modal = document.getElementById('pdf-upload-limit-modal');
                    if (closeBtn) {
                        closeBtn.addEventListener('click', hideUploadLimitModal);
                    }
                    if (modal) {
                        modal.addEventListener('click', (event) => {
                            if (event.target === modal) {
                                hideUploadLimitModal();
                            }
                        });
                    }

                    const serverError = @json($errors->first());
                    if (shouldShowUploadLimitModal(serverError)) {
                        showUploadLimitModal();
                    }
                });
            </script>

            <div class="card">
                <!-- AI Design Callout -->
                <div style="background: var(--callout-bg); border: 1px solid var(--callout-border); border-radius: 14px; padding: 24px; margin-bottom: 32px; display: flex; align-items: center; justify-content: space-between; position: relative; overflow: hidden;">
                    <div style="position: absolute; top: -50px; right: -50px; width: 200px; height: 200px; background: var(--accent); opacity: 0.05; border-radius: 50%; filter: blur(40px);"></div>
                    <div style="position: relative; z-index: 2; max-width: 700px;">
                        <h2 style="color: var(--accent); margin: 0 0 8px; font-size: 20px; display: flex; align-items: center; gap: 10px;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 1 0 10 10H12V2z"></path><path d="M12 12 2.1 10.5M12 12l9.9-1.5M12 12l-1.5 9.9"></path></svg>
                            Design with AI
                        </h2>
                        <p style="font-size: 14px; color: var(--muted); line-height: 1.5; margin: 0;">
                            Create a new AI design session for any document below to extract, restructure, and design using our generative models.
                        </p>
                        
                        <div style="margin-top: 16px;">
                            @if($documents->count() > 0)
                                <form action="{{ route('documents.createAi') }}" method="POST">
                                    @csrf
                                    <input type="hidden" name="document_id" value="{{ $documents->first()->id }}">
                                    <button type="submit" style="background: var(--accent); color: #053322; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: opacity 0.2s;" onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
                                        <span>Launch AI Editor</span>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                    </button>
                                </form>
                            @else
                                <button onclick="document.getElementById('document-input')?.click()" style="background: var(--accent); color: #053322; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: opacity 0.2s;" onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
                                    <span>Upload & Start</span>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
                                </button>
                            @endif
                        </div>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <h2 style="margin: 0;">Your PDFs</h2>
                        @if ($documents->count() > 0)
                        <div style="display: flex; align-items: center; gap: 6px; padding-left: 12px; border-left: 1px solid rgba(255,255,255,0.1);">
                            <input type="checkbox" id="select-all-checkbox" onchange="toggleSelectAll(this)" style="cursor: pointer; width: 16px; height: 16px; accent-color: var(--accent);">
                            <label for="select-all-checkbox" style="font-size: 13px; cursor: pointer; color: var(--muted); user-select: none;">Select All</label>
                        </div>
                        @endif
                    </div>
                    <button id="bulk-delete-btn" class="btn-danger" style="display: none; padding: 8px 16px; font-size: 13px;" onclick="submitBulkDelete()">
                        Delete Selected (<span id="selected-count">0</span>)
                    </button>
                </div>
                
                <form id="bulk-delete-form" action="{{ route('documents.bulkDestroy') }}" method="POST" style="display: none;">
                    @csrf
                </form>

                <div class="docs">
                    @forelse ($documents as $document)
                        <div class="doc">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <input type="checkbox" class="doc-checkbox" value="{{ $document->id }}" onchange="updateBulkState()" style="cursor: pointer; width: 18px; height: 18px; accent-color: var(--accent);">
                                <div>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        {{ $document->original_name }}
                                        @if($document->mode === 'guided')
                                            <span style="background: var(--guided-pill-bg); color: var(--accent); padding: 2px 8px; border-radius: 99px; font-size: 10px; font-weight: 700; text-transform: uppercase;">GUIDED</span>
                                        @elseif($document->mode === 'ai')
                                            <span style="background: var(--ai-pill-bg); color: var(--ai-pill-text); padding: 2px 8px; border-radius: 99px; font-size: 10px; font-weight: 700; text-transform: uppercase;">AI</span>
                                        @endif
                                    </div>
                                    <div class="tag">{{ number_format($document->size_bytes / 1024, 1) }} KB</div>
                                </div>
                            </div>
                            <div class="doc-actions">
                                <form action="{{ route('documents.createAi') }}" method="POST" style="display: inline-flex;">
                                    @csrf
                                    <input type="hidden" name="document_id" value="{{ $document->id }}">
                                    <button type="submit" class="btn-secondary" style="border-color: rgba(77,208,168,0.4); color: var(--accent); padding: 7px 14px; font-size: 12px;">
                                        Design with AI
                                    </button>
                                </form>

                                @if($document->mode === 'guided')
                                    <a href="{{ route('documents.guided', $document) }}">Edit</a>
                                @elseif($document->mode === 'ai')
                                    <a href="{{ route('documents.ai', $document) }}">Edit</a>
                                @else
                                    <a href="{{ route('documents.edit', $document) }}">Edit</a>
                                @endif
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
            </div>
        </main>

        <div id="pdf-upload-limit-modal" class="limit-modal" aria-hidden="true">
            <div class="limit-modal-card">
                <h3 class="limit-modal-title">Out of PDF uploads</h3>
                <p class="limit-modal-copy">You are out of PDF uploads for this month. Please look at the subscription plans to continue.</p>
                <div class="limit-modal-actions">
                    <a href="/portal/subscription"><button type="button">View Subscription Plans</button></a>
                    <button id="pdf-upload-limit-close" type="button" class="btn-secondary">Close</button>
                </div>
            </div>
        </div>


    </body>
</html>
