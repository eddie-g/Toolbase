<!DOCTYPE html>
<html lang="en" x-data="{ 
    darkMode: localStorage.getItem('darkMode') === 'true' || false,
    toggleDarkMode() {
        this.darkMode = !this.darkMode;
        localStorage.setItem('darkMode', this.darkMode);
    }
}" x-init="$watch('darkMode', val => document.documentElement.classList.toggle('dark', val))" :class="{ 'dark': darkMode }">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>PDF Uploader</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
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
                padding: 120px 20px 72px;
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
            .tpl-tab-btn:hover {
                color: var(--ink) !important;
            }
            .tpl-tab-btn.active:hover {
                color: var(--accent) !important;
            }

        </style>
    </head>
    <body>
        <header class="fixed top-0 left-0 right-0 z-50 bg-white/80 dark:bg-gray-900/80 backdrop-blur-lg border-b border-gray-200 dark:border-gray-800">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-20">
                    <div class="flex items-center gap-3">
                        <svg class="h-10 w-10 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <a href="{{ route('home') }}" class="text-2xl font-bold text-gray-900 dark:text-white" style="text-decoration:none;">Toolbase</a>
                        <span class="hidden sm:inline-block text-xs bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-400 px-2 py-1 rounded-full font-medium">v2.2</span>
                    </div>

                    <nav class="hidden md:flex items-center gap-8">
                        <a href="{{ route('home') }}#features" class="text-gray-600 dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-400 transition">Features</a>
                        <a href="{{ route('home') }}#dashboards" class="text-gray-600 dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-400 transition">Dashboards</a>
                        <a href="{{ route('home') }}#pdf-features" class="text-gray-600 dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-400 transition">PDF Editor</a>
                        <a href="#" class="text-gray-600 dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-400 transition">Docs</a>
                    </nav>

                    <div class="flex items-center gap-4">
                        <button @click="toggleDarkMode()" class="p-2 rounded-lg text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition" type="button" style="background:transparent;">
                            <svg x-show="!darkMode" class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                            </svg>
                            <svg x-show="darkMode" class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                            </svg>
                        </button>

                        @guest
                            <a href="{{ route('filament.admin.auth.login') }}" class="hidden sm:inline-flex items-center gap-2 px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition" style="text-decoration:none;">
                                Login
                            </a>
                        @endguest

                        @auth
                            <div class="relative ml-2" x-data="{ open: false }">
                                <button @click="open = !open" type="button" class="flex items-center justify-center w-10 h-10 rounded-full bg-gray-700 text-white hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-900 focus:ring-white transition" id="user-menu-button" aria-expanded="false" aria-haspopup="true" style="padding:0;">
                                    <span class="sr-only">Open user menu</span>
                                    @if(Auth::user()->avatar)
                                        <img class="h-9 w-9 rounded-full object-cover border-2 border-gray-600" src="{{ Auth::user()->avatar }}" alt="">
                                    @else
                                        <svg class="h-6 w-6 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                                    @endif
                                </button>

                                <div x-show="open" @click.away="open = false" x-transition:enter="transition ease-out duration-100" x-transition:enter-start="transform opacity-0 scale-95" x-transition:enter-end="transform opacity-100 scale-100" x-transition:leave="transition ease-in duration-75" x-transition:leave-start="transform opacity-100 scale-100" x-transition:leave-end="transform opacity-0 scale-95" class="absolute right-0 z-50 mt-2 w-48 origin-top-right rounded-xl bg-white dark:bg-[#1a2332] py-2 shadow-2xl ring-1 ring-black/5 dark:ring-white/10 focus:outline-none border border-gray-200 dark:border-gray-700/50 dark:backdrop-blur-xl" role="menu" aria-orientation="vertical" aria-labelledby="user-menu-button" tabindex="-1">
                                    <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700/50 mb-1">
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Signed in as</p>
                                        <p class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ Auth::user()->name }}</p>
                                    </div>
                                    <a href="/admin" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700/50 dark:hover:text-white transition-colors" role="menuitem" tabindex="-1">Dashboard</a>
                                    <a href="{{ route('filament.admin.pages.security') }}" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700/50 dark:hover:text-white transition-colors" role="menuitem" tabindex="-1">Security</a>
                                    <form method="POST" action="{{ route('filament.admin.auth.logout') }}">
                                        @csrf
                                        <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-gray-50 dark:hover:bg-gray-700/50 hover:text-red-700 dark:hover:text-red-300 transition-colors" role="menuitem" tabindex="-1" style="border-radius:0;">Sign out</button>
                                    </form>
                                </div>
                            </div>
                        @endauth
                    </div>
                </div>
            </div>
        </header>

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
                            <span style="background:{{ $loop->first ? 'rgba(77,208,168,0.15)' : 'rgba(255,255,255,0.08)' }}; font-size:11px; padding:1px 7px; border-radius:10px; font-weight:700;">{{ $guidedTemplatesByType[$catKey]->count() ?? 0 }}</span>
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
                            <button type="submit" class="tpl-card" style="background:rgba(255,255,255,0.04);font:inherit;color:inherit;padding:0;width:100%;text-align:left;border-radius:14px;">
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
                        btn.querySelector('span').style.background = isActive ? 'rgba(77,208,168,0.15)' : 'rgba(255,255,255,0.08)';
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

                // Initial check in case browser restores checked state on reload
                document.addEventListener('DOMContentLoaded', updateBulkState);
            </script>

            <div class="card">
                <!-- AI Design Callout -->
                <div style="background: linear-gradient(135deg, rgba(77,208,168,0.05) 0%, rgba(11,19,32,0.5) 100%); border: 1px solid rgba(77,208,168,0.2); border-radius: 14px; padding: 24px; margin-bottom: 32px; display: flex; align-items: center; justify-content: space-between; position: relative; overflow: hidden;">
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
                                <button onclick="document.querySelector('input[type=file]').click()" style="background: var(--accent); color: #053322; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: opacity 0.2s;" onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
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
                                            <span style="background: rgba(77, 208, 168, 0.15); color: var(--accent); padding: 2px 8px; border-radius: 99px; font-size: 10px; font-weight: 700; text-transform: uppercase;">GUIDED</span>
                                        @elseif($document->mode === 'ai')
                                            <span style="background: rgba(168, 85, 247, 0.15); color: #c084fc; padding: 2px 8px; border-radius: 99px; font-size: 10px; font-weight: 700; text-transform: uppercase;">AI</span>
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


    </body>
</html>
