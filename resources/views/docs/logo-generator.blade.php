<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <script>if(localStorage.getItem('darkMode')==='true')document.documentElement.classList.add('dark');</script>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>AI Logo Generator — User Guide · Netkit</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        [x-cloak] { display: none !important; }

        /* Prose base */
        .prose h2 { font-size: 1.5rem; font-weight: 700; color: inherit; margin-top: 2.5rem; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid; }
        .prose h3 { font-size: 1.125rem; font-weight: 600; color: inherit; margin-top: 1.75rem; margin-bottom: 0.75rem; }
        .prose h4 { font-size: 0.9375rem; font-weight: 600; color: inherit; margin-top: 1.5rem; margin-bottom: 0.5rem; }
        .prose p { margin-bottom: 1rem; line-height: 1.8; }
        .prose ul { margin-bottom: 1rem; padding-left: 1.5rem; list-style: disc; }
        .prose ol { margin-bottom: 1rem; padding-left: 1.5rem; list-style: decimal; }
        .prose li { margin-bottom: 0.35rem; line-height: 1.7; }
        .prose a { color: #10b981; text-decoration: underline; text-underline-offset: 3px; }
        .prose a:hover { color: #059669; }
        .prose code { font-size: 0.85em; font-family: monospace; padding: 0.15em 0.4em; border-radius: 4px; }
        .prose strong { font-weight: 600; }

        /* Dark prose overrides */
        .dark .prose h2 { border-color: #374151; color: #f9fafb; }
        .dark .prose h3 { color: #f3f4f6; }
        .dark .prose h4 { color: #e5e7eb; }
        .dark .prose p { color: #d1d5db; }
        .dark .prose li { color: #d1d5db; }
        .dark .prose code { background: #1f2937; color: #a5f3fc; }
        .dark .prose strong { color: #f9fafb; }
        .prose h2 { color: #111827; border-color: #e5e7eb; }
        .prose h3 { color: #1f2937; }
        .prose h4 { color: #374151; }
        .prose p { color: #374151; }
        .prose li { color: #374151; }
        .prose code { background: #f1f5f9; color: #0f172a; }

        /* Callout blocks */
        .callout { border-left: 4px solid; border-radius: 0 0.5rem 0.5rem 0; padding: 1rem 1.25rem; margin: 1.5rem 0; }
        .callout-tip { border-color: #10b981; background: #f0fdf4; }
        .callout-note { border-color: #3b82f6; background: #eff6ff; }
        .callout-warning { border-color: #f59e0b; background: #fffbeb; }
        .dark .callout-tip { background: rgba(16,185,129,0.08); }
        .dark .callout-note { background: rgba(59,130,246,0.08); }
        .dark .callout-warning { background: rgba(245,158,11,0.08); }

        /* TOC link active state */
        .toc-link.active { color: #10b981; font-weight: 600; }
        .dark .toc-link.active { color: #34d399; }

        /* Left sidebar active link */
        .nav-link.active { color: #10b981; background: #f0fdf4; border-radius: 0.375rem; }
        .dark .nav-link.active { color: #34d399; background: rgba(16,185,129,0.1); }

        /* Steps */
        .step { display: flex; gap: 1rem; margin-bottom: 1.25rem; }
        .step-num { flex-shrink: 0; width: 2rem; height: 2rem; border-radius: 9999px; background: #10b981; color: white; font-weight: 700; font-size: 0.875rem; display: flex; align-items: center; justify-content: center; margin-top: 0.1rem; }
        .step-content { flex: 1; }

        /* Scrollbar */
        .sidebar-scroll::-webkit-scrollbar { width: 4px; }
        .sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
        .sidebar-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 2px; }
        .dark .sidebar-scroll::-webkit-scrollbar-thumb { background: #374151; }

        /* Feature grid */
        .feature-card { border-radius: 0.75rem; border: 1px solid #e5e7eb; padding: 1rem 1.25rem; }
        .dark .feature-card { border-color: #374151; background: #111827; }
        .feature-card { background: #f9fafb; }

        /* Image placeholder */
        .img-placeholder { width: 100%; border-radius: 0.75rem; border: 2px dashed #d1d5db; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 2.5rem; text-align: center; color: #9ca3af; }
        .dark .img-placeholder { border-color: #374151; }

        @media (max-width: 1023px) {
            .lg-sidebar { display: none; }
        }
    </style>
</head>
<body class="bg-white dark:bg-gray-950 antialiased" x-data="{ mobileNav: false, mobileToc: false }">

    {{-- ─── Top Navigation Bar ─────────────────────────────────────────── --}}
    <header class="fixed top-0 left-0 right-0 z-50 bg-white/90 dark:bg-gray-950/90 backdrop-blur-lg border-b border-gray-200 dark:border-gray-800 h-16">
        <div class="flex items-center justify-between h-full px-4 sm:px-6">
            {{-- Brand --}}
            <div class="flex items-center gap-4">
                <button @click="mobileNav = !mobileNav" class="lg:hidden p-2 rounded-lg text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800 transition" type="button">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <a href="{{ route('home') }}" class="flex items-center gap-2.5 no-underline">
                    <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <span class="text-xl font-bold text-gray-900 dark:text-white">Netkit</span>
                </a>
                <span class="hidden sm:inline-flex items-center gap-1 text-sm text-gray-400 dark:text-gray-500">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    User Guide
                </span>
            </div>

            <div class="flex items-center gap-3">
                {{-- Version badge --}}
                <span class="hidden sm:inline-flex px-2.5 py-1 rounded-full bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 text-xs font-semibold">v1.0</span>

                {{-- Theme toggle --}}
                <button data-theme-toggle class="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition" type="button">
                    <svg class="w-5 h-5 block dark:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                    <svg class="w-5 h-5 hidden dark:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                </button>

                <a href="{{ route('domainSearch.logoGenerator') }}" class="hidden sm:inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    Launch Logo Generator
                </a>
            </div>
        </div>
    </header>

    {{-- ─── Mobile Nav Overlay ─────────────────────────────────────────── --}}
    <div x-show="mobileNav" x-cloak @click="mobileNav = false" class="fixed inset-0 z-40 bg-black/50 lg:hidden backdrop-blur-sm"></div>
    <aside x-show="mobileNav" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
        class="fixed top-16 left-0 z-50 w-72 h-[calc(100vh-4rem)] bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 overflow-y-auto sidebar-scroll lg:hidden">
        @include('docs._sidebar')
    </aside>

    {{-- ─── Page Layout ─────────────────────────────────────────────────── --}}
    <div class="flex pt-16 min-h-screen">

        {{-- Left Sidebar (desktop) --}}
        <aside class="hidden lg:block w-64 fixed top-16 bottom-0 left-0 border-r border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-950 overflow-y-auto sidebar-scroll z-30">
            @include('docs._sidebar')
        </aside>

        {{-- Main Content --}}
        <main class="flex-1 min-w-0 lg:ml-64 xl:mr-64">
            <div class="max-w-3xl mx-auto px-6 sm:px-8 py-12">

                {{-- ── Hero ───────────────────────────────────────────────── --}}
                <div class="mb-10">
                    <div class="flex items-center gap-2 text-xs font-medium text-gray-400 dark:text-gray-500 mb-4">
                        <a href="{{ route('home') }}" class="hover:text-emerald-500 transition">Home</a>
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        <span>User Guide</span>
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        <span class="text-gray-600 dark:text-gray-300">AI Logo Generator</span>
                    </div>

                    <div class="flex items-center gap-4 mb-5">
                        <div class="w-14 h-14 rounded-2xl bg-emerald-100 dark:bg-emerald-900/40 flex items-center justify-center flex-shrink-0">
                            <svg class="w-7 h-7 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <div>
                            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 dark:text-white leading-tight">AI Logo Generator</h1>
                            <p class="text-gray-500 dark:text-gray-400 mt-1">Complete guide to generating professional brand logos with AI</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 text-xs font-medium">
                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="10" r="8"/></svg>
                            Flux · DALL-E 3 · Recraft
                        </span>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 text-xs font-medium">Credit-based</span>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-300 text-xs font-medium">Vector & Raster</span>
                    </div>
                </div>

                <hr class="border-gray-200 dark:border-gray-800 mb-10" />

                {{-- ── Introduction ────────────────────────────────────────── --}}
                <section id="introduction" class="prose mb-12 scroll-mt-24">
                    <h2>Introduction</h2>
                    <p>
                        The <strong>Netkit AI Logo Generator</strong> lets you create professional brand logos in seconds using cutting-edge AI image models.
                        Simply describe what you want, choose your style and colour palette, and the generator produces studio-quality logos ready for download,
                        further editing, or use directly in the PDF editor.
                    </p>
                    <p>
                        Logos can be generated as <strong>raster images</strong> (PNG / BMP) or as scalable <strong>vector graphics</strong> (SVG),
                        depending on the model and output format you pick. Every generation is charged against your credit balance at transparent,
                        per-image rates — no subscriptions required.
                    </p>

                    <div class="callout callout-note not-prose">
                        <div class="flex gap-2.5">
                            <svg class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                            <p class="text-sm text-blue-800 dark:text-blue-200 m-0"><strong>Login required.</strong> You must be signed in to use the AI Logo Generator and to see pricing options, style panels, and your credit balance.</p>
                        </div>
                    </div>
                </section>

                {{-- ── Getting Started ──────────────────────────────────────── --}}
                <section id="getting-started" class="mb-12 scroll-mt-24">
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white pb-3 border-b border-gray-200 dark:border-gray-800 mb-6">Getting Started</h2>

                    <div class="space-y-5">
                        <div class="step">
                            <div class="step-num">1</div>
                            <div class="step-content">
                                <h4 class="font-semibold text-gray-900 dark:text-white mb-1">Navigate to the Logo Generator</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400 m-0">Click <strong>Logo Generator</strong> in the top navigation, or go to <a href="{{ route('domainSearch.logoGenerator') }}" class="text-emerald-600 dark:text-emerald-400 underline underline-offset-2">/logo-generator</a>. You can also reach it from the Domain Search page via the <em>Logo Generator</em> tab.</p>
                            </div>
                        </div>
                        <div class="step">
                            <div class="step-num">2</div>
                            <div class="step-content">
                                <h4 class="font-semibold text-gray-900 dark:text-white mb-1">Log in to your account</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400 m-0">All generation controls, the credit balance, style panel, palette, shape, and background options are locked behind authentication. Click <strong>Log In</strong> if you see the "Login Required" prompt.</p>
                            </div>
                        </div>
                        <div class="step">
                            <div class="step-num">3</div>
                            <div class="step-content">
                                <h4 class="font-semibold text-gray-900 dark:text-white mb-1">Top up your credits (if needed)</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400 m-0">Your current credit balance is shown in the right sidebar. The <strong>Generate</strong> button is disabled when your balance is insufficient for the selected number of images.</p>
                            </div>
                        </div>
                        <div class="step">
                            <div class="step-num">4</div>
                            <div class="step-content">
                                <h4 class="font-semibold text-gray-900 dark:text-white mb-1">Fill in your prompt and hit Generate</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400 m-0">Enter a domain name (optional when Icon Only mode is on), write a description of your logo, adjust any settings, and click <strong>Generate Logos</strong>.</p>
                            </div>
                        </div>
                    </div>
                </section>

                {{-- ── The Generator Form ───────────────────────────────────── --}}
                <section id="the-form" class="mb-12 scroll-mt-24">
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white pb-3 border-b border-gray-200 dark:border-gray-800 mb-6">The Generator Form</h2>

                    {{-- Domain Name --}}
                    <h3 id="domain-name" class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-2 scroll-mt-24">Domain / Brand Name</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-4">
                        Enter the brand or domain you want the logo made for (e.g. <code class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-xs font-mono">acme.io</code> or <code class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-xs font-mono">BlueSpark</code>).
                        This field is <strong>optional</strong> when <em>Icon Only</em> mode is enabled — useful when you just want a standalone graphic without any text.
                    </p>

                    {{-- Prompt --}}
                    <h3 id="prompt" class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-2 scroll-mt-24">Logo Prompt <span class="text-red-400 font-normal text-sm">(required)</span></h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-3">
                        Describe your desired logo in plain language. Be specific about the subject, mood, and any visual elements. Good prompts lead to dramatically better results.
                    </p>
                    <div class="feature-card mb-5">
                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Prompt Examples</p>
                        <div class="space-y-2">
                            <div class="flex gap-2">
                                <svg class="w-4 h-4 text-emerald-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                <p class="text-sm text-gray-600 dark:text-gray-400 m-0"><code class="text-xs bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded font-mono">a minimalist shield with a lightning bolt, tech startup feel</code></p>
                            </div>
                            <div class="flex gap-2">
                                <svg class="w-4 h-4 text-emerald-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                <p class="text-sm text-gray-600 dark:text-gray-400 m-0"><code class="text-xs bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded font-mono">abstract ocean waves, bold and modern, navy blue and gold</code></p>
                            </div>
                            <div class="flex gap-2">
                                <svg class="w-4 h-4 text-emerald-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                <p class="text-sm text-gray-600 dark:text-gray-400 m-0"><code class="text-xs bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded font-mono">a rocketship launching into a star field, vintage retro sticker look</code></p>
                            </div>
                        </div>
                    </div>

                    {{-- Text in Logo toggle --}}
                    <h3 id="text-in-logo" class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-2 scroll-mt-24">Text in Logo / Icon Only</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-3">
                        Toggle this switch to control whether the generated logo includes your brand name as text.
                    </p>
                    <div class="grid sm:grid-cols-2 gap-3 mb-5">
                        <div class="feature-card">
                            <p class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-1 flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 inline-block"></span>
                                Text in Logo (on)
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 m-0">The domain / brand name is embedded as text inside the logo image. The Domain Name field is required.</p>
                        </div>
                        <div class="feature-card">
                            <p class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-1 flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-full bg-gray-400 inline-block"></span>
                                Icon Only (off)
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 m-0">A pure graphic/icon is generated without text. Great for favicon-level icons or marks. Domain Name becomes optional.</p>
                        </div>
                    </div>

                    <div class="callout callout-warning not-prose">
                        <div class="flex gap-2.5">
                            <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                            <p class="text-sm text-amber-800 dark:text-amber-200 m-0">The <strong>Chrome</strong> and <strong>Dot Matrix</strong> styles are restricted to icon-only mode. You cannot enable "Text in Logo" while these styles are selected.</p>
                        </div>
                    </div>

                    {{-- Output format --}}
                    <h3 id="output-format" class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-2 scroll-mt-24">Output Format</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-3">
                        Choose between raster and vector output. Availability depends on the AI model selected.
                    </p>
                    <div class="overflow-x-auto mb-5">
                        <table class="w-full text-sm border-collapse">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-800/60">
                                    <th class="text-left px-4 py-2.5 font-semibold text-gray-700 dark:text-gray-300 rounded-tl-lg border border-gray-200 dark:border-gray-700">Format</th>
                                    <th class="text-left px-4 py-2.5 font-semibold text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700">Type</th>
                                    <th class="text-left px-4 py-2.5 font-semibold text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700">Available With</th>
                                    <th class="text-left px-4 py-2.5 font-semibold text-gray-700 dark:text-gray-300 rounded-tr-lg border border-gray-200 dark:border-gray-700">Best For</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <tr class="bg-white dark:bg-transparent">
                                    <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300 border-x border-gray-200 dark:border-gray-700"><span class="font-mono font-bold">Raster</span></td>
                                    <td class="px-4 py-2.5 text-gray-600 dark:text-gray-400 border-x border-gray-200 dark:border-gray-700">PNG / BMP</td>
                                    <td class="px-4 py-2.5 text-gray-600 dark:text-gray-400 border-x border-gray-200 dark:border-gray-700">Flux, DALL-E 3, Recraft</td>
                                    <td class="px-4 py-2.5 text-gray-600 dark:text-gray-400 border-x border-gray-200 dark:border-gray-700">Web, social, general use</td>
                                </tr>
                                <tr class="bg-gray-50/50 dark:bg-gray-800/20">
                                    <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300 border-x border-b border-gray-200 dark:border-gray-700 rounded-bl-lg"><span class="font-mono font-bold">Vector</span></td>
                                    <td class="px-4 py-2.5 text-gray-600 dark:text-gray-400 border-x border-b border-gray-200 dark:border-gray-700">SVG</td>
                                    <td class="px-4 py-2.5 text-gray-600 dark:text-gray-400 border-x border-b border-gray-200 dark:border-gray-700">Flux, Recraft</td>
                                    <td class="px-4 py-2.5 text-gray-600 dark:text-gray-400 border-x border-b border-gray-200 dark:border-gray-700 rounded-br-lg">Print, scalable at any size</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    {{-- Image count --}}
                    <h3 id="image-count" class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-2 scroll-mt-24">Number of Images</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-4">
                        Use the <strong>–</strong> and <strong>+</strong> buttons to select how many logos to generate in one request (1 – 8). Each image is billed separately. The pricing breakdown updates in real time as you change the count.
                    </p>

                    {{-- Seed --}}
                    <h3 id="seed" class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-2 scroll-mt-24">Seed (Reproducibility)</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-4">
                        After generating logos, each image card shows a <strong>"Use Seed"</strong> button. Clicking it locks the generation to that image's seed number and fills the seed input field, letting you reproduce or iterate on a specific result. Clearing the seed field restores random generation.
                    </p>
                </section>

                {{-- ── AI Models ─────────────────────────────────────────────── --}}
                <section id="ai-models" class="mb-12 scroll-mt-24">
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white pb-3 border-b border-gray-200 dark:border-gray-800 mb-6">AI Models</h2>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-6">
                        Three AI models are available. Each has a different aesthetic and supports different output features. Switch between them using the model selector at the top of the form.
                    </p>

                    <div class="space-y-4">
                        {{-- Flux --}}
                        <div class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                            <div class="flex items-center gap-3 px-5 py-3.5 bg-gray-50 dark:bg-gray-800/60 border-b border-gray-200 dark:border-gray-700">
                                <div class="w-8 h-8 rounded-lg bg-blue-600 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20"><path d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z"/></svg>
                                </div>
                                <div>
                                    <p class="font-bold text-gray-900 dark:text-white text-sm m-0">Flux</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 m-0">Default · Raster + Vector</p>
                                </div>
                                <span class="ml-auto px-2 py-0.5 rounded-full bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 text-xs font-semibold">Recommended</span>
                            </div>
                            <div class="px-5 py-4">
                                <p class="text-sm text-gray-600 dark:text-gray-400 m-0">A high-quality open-weight diffusion model excellent for detailed, photorealistic, and artistic logos. Supports both raster (PNG) and vector (SVG) output. Works with style selection, colour palettes, shape containers, and seed control. Generally the best all-around choice.</p>
                            </div>
                        </div>

                        {{-- DALL-E 3 --}}
                        <div class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                            <div class="flex items-center gap-3 px-5 py-3.5 bg-gray-50 dark:bg-gray-800/60 border-b border-gray-200 dark:border-gray-700">
                                <div class="w-8 h-8 rounded-lg bg-gray-800 dark:bg-gray-700 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                                </div>
                                <div>
                                    <p class="font-bold text-gray-900 dark:text-white text-sm m-0">DALL-E 3</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 m-0">OpenAI · Raster only</p>
                                </div>
                            </div>
                            <div class="px-5 py-4">
                                <p class="text-sm text-gray-600 dark:text-gray-400 m-0">OpenAI's flagship image model. Produces highly coherent images with good prompt adherence. <strong>Raster output only</strong> (PNG or BMP). Supports Chrome, Retro, 8-Bit, Dot Matrix, Lego, and Minimalist styles. The model uses icon-only mode by default for most styles. Colour palettes are not available with DALL-E 3.</p>
                            </div>
                        </div>

                        {{-- Recraft --}}
                        <div class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                            <div class="flex items-center gap-3 px-5 py-3.5 bg-gray-50 dark:bg-gray-800/60 border-b border-gray-200 dark:border-gray-700">
                                <div class="w-8 h-8 rounded-lg bg-violet-600 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42"/></svg>
                                </div>
                                <div>
                                    <p class="font-bold text-gray-900 dark:text-white text-sm m-0">Recraft</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 m-0">Design-focused · Raster + Vector + Substyles</p>
                                </div>
                                <span class="ml-auto px-2 py-0.5 rounded-full bg-violet-100 dark:bg-violet-900/40 text-violet-700 dark:text-violet-300 text-xs font-semibold">Pro</span>
                            </div>
                            <div class="px-5 py-4">
                                <p class="text-sm text-gray-600 dark:text-gray-400 m-0">A design-specialised model with fine-grained sub-style control (e.g. <em>Flat Illustration</em>, <em>3D Render</em>, <em>Hand-drawn</em>). Supports both raster and vector. Unique <strong>Detail Level</strong> (Min / Medium / Max) control. Best for illustration-heavy or brand-kit quality logos. Generally slightly higher per-image cost.</p>
                            </div>
                        </div>
                    </div>
                </section>

                {{-- ── Style Sidebar ─────────────────────────────────────────── --}}
                <section id="sidebar-options" class="mb-12 scroll-mt-24">
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white pb-3 border-b border-gray-200 dark:border-gray-800 mb-6">Right Sidebar Options</h2>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-6">
                        The right panel (visible on large screens, accessible via the palette and background buttons on mobile) gives you control over the visual properties of your logo <em>without</em> having to touch the prompt.
                    </p>

                    {{-- Balance --}}
                    <h3 id="balance" class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-2 scroll-mt-24">Credit Balance</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-4">
                        Displayed at the top of the sidebar. Shown in USD with 4 decimal places (e.g. <code class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-xs font-mono">$2.4130</code>). The balance updates after each generation. It turns <span class="text-red-500 font-semibold">red</span> when it falls below $0.01. The Generate button is automatically disabled if the balance is too low for the current price.
                    </p>

                    {{-- Style --}}
                    <h3 id="style" class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-2 scroll-mt-24">Style</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-4">
                        Click the Style card to open the style picker modal. Available styles depend on the selected AI model:
                    </p>
                    <div class="grid sm:grid-cols-2 gap-3 mb-5">
                        <div>
                            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Flux / Recraft styles</p>
                            <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-1 list-none pl-0 m-0">
                                <li class="flex gap-2"><span class="text-emerald-500">›</span> Professional</li>
                                <li class="flex gap-2"><span class="text-emerald-500">›</span> Fantasy</li>
                                <li class="flex gap-2"><span class="text-emerald-500">›</span> Future</li>
                                <li class="flex gap-2"><span class="text-emerald-500">›</span> Retro</li>
                                <li class="flex gap-2"><span class="text-emerald-500">›</span> Minimalist</li>
                            </ul>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">DALL-E 3 styles</p>
                            <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-1 list-none pl-0 m-0">
                                <li class="flex gap-2"><span class="text-emerald-500">›</span> Chrome <span class="text-[10px] text-gray-400">(icon-only)</span></li>
                                <li class="flex gap-2"><span class="text-emerald-500">›</span> Retro</li>
                                <li class="flex gap-2"><span class="text-emerald-500">›</span> 8-Bit</li>
                                <li class="flex gap-2"><span class="text-emerald-500">›</span> Dot Matrix <span class="text-[10px] text-gray-400">(icon-only)</span></li>
                                <li class="flex gap-2"><span class="text-emerald-500">›</span> Lego</li>
                            </ul>
                        </div>
                    </div>

                    {{-- Palette --}}
                    <h3 id="palette" class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-2 scroll-mt-24">Colour Palette</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-4">
                        Select a preset colour palette or pick <strong>Custom</strong> to define 2–5 hex colours of your own. When a palette is selected, it is sent to the AI as a constraint — the generator will try to use those colours in the logo. The sidebar on desktop shows a live 2-column palette grid. On mobile, tap the palette button below the output format selector to open a slide-out panel.
                    </p>
                    <div class="callout callout-tip not-prose">
                        <div class="flex gap-2.5">
                            <svg class="w-5 h-5 text-emerald-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                            <p class="text-sm text-emerald-800 dark:text-emerald-200 m-0"><strong>Tip:</strong> Colour palettes are not applied when using DALL-E 3. Switch to Flux or Recraft to use palette control.</p>
                        </div>
                    </div>

                    {{-- Shape --}}
                    <h3 id="shape" class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-2 scroll-mt-24">Shape Container</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-4">
                        Available for <strong>Flux</strong> and <strong>Recraft</strong> only. Constrains the logo shape to a geometric container. Options: <em>None</em>, <em>Circle</em>, <em>Square</em>, <em>Triangle</em>, <em>Pentagon</em>, <em>Hexagon</em>. Selecting a shape informs the AI that the final icon should fit neatly within that boundary.
                    </p>

                    {{-- Background --}}
                    <h3 id="background" class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-2 scroll-mt-24">Background</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-4">
                        Choose the background colour for your logo: <strong>White</strong>, <strong>Black</strong>, or <strong>Custom</strong> (any hex colour via a colour picker). Selecting <em>Transparent</em> (available as a generated output) is handled post-generation by the Background Removal tool. The selected background colour affects the generation prompt and is displayed in the image preview checkerboard pattern if transparent.
                    </p>
                </section>

                {{-- ── Pricing ───────────────────────────────────────────────── --}}
                <section id="pricing" class="mb-12 scroll-mt-24">
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white pb-3 border-b border-gray-200 dark:border-gray-800 mb-6">Pricing & Credits</h2>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-5">
                        Every AI generation consumes credits from your account balance. Pricing is calculated per image and varies by model, output format, and PRO mode. A live breakdown is shown directly below the image count selector:
                    </p>
                    <div class="feature-card mb-5 space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600 dark:text-gray-400">Per Image</span>
                            <span class="font-mono text-gray-800 dark:text-gray-200">$0.0030</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600 dark:text-gray-400">Base API Cost</span>
                            <span class="font-mono text-gray-800 dark:text-gray-200">$0.0140</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600 dark:text-gray-400">Markup (50%)</span>
                            <span class="font-mono text-gray-800 dark:text-gray-200">+ $0.0070</span>
                        </div>
                        <div class="flex justify-between font-semibold text-sm border-t border-gray-200 dark:border-gray-700 pt-2">
                            <span class="text-gray-800 dark:text-gray-200">Total Cost (4 images)</span>
                            <span class="font-mono text-gray-900 dark:text-white">$0.0280</span>
                        </div>
                    </div>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed">
                        Prices are fetched live from the pricing API when you change model, format, count, or toggle PRO mode. If the pricing API is unavailable, fallback rates are used. You can top up your balance at any time from your account dashboard.
                    </p>
                </section>

                {{-- ── Working with Results ─────────────────────────────────── --}}
                <section id="results" class="mb-12 scroll-mt-24">
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white pb-3 border-b border-gray-200 dark:border-gray-800 mb-6">Working with Results</h2>

                    <h3 id="viewing" class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-2 scroll-mt-24">Viewing & Zooming</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-4">
                        Generated logos appear in a 2×4 grid below the form. Click any logo to open a full-screen lightbox preview with the correct background colour applied. Press <kbd class="px-1.5 py-0.5 rounded border border-gray-300 dark:border-gray-600 text-xs font-mono text-gray-700 dark:text-gray-300">Esc</kbd> or click outside the image to close.
                    </p>

                    <h3 id="downloading" class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-2 scroll-mt-24">Downloading</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-4">
                        Each image card has a <strong>Download</strong> button. For vector (SVG) outputs the SVG file is downloaded directly. For raster outputs (PNG/BMP) the corresponding image file is downloaded.
                    </p>

                    <h3 id="use-as-prompt" class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-2 scroll-mt-24">Use as Prompt</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-4">
                        Click <strong>Use as Prompt</strong> on any image card to send that logo to the AI for analysis. The AI describes the visual content and auto-fills the prompt field — perfect for iterating on a design you like.
                    </p>

                    <h3 id="remove-background" class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-2 scroll-mt-24">Remove Background</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-4">
                        Click <strong>Remove Background</strong> to strip the background from a raster logo, producing a PNG with a transparent background. This is processed server-side and replaces the image in-place. Not available for vector outputs.
                    </p>

                    <h3 id="upscale" class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-2 scroll-mt-24">Upscale</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-4">
                        Click <strong>Upscale</strong> to increase the resolution of a raster logo using AI super-resolution. Useful before sending the logo to print or large-format display.
                    </p>

                    <h3 id="open-in-editor" class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-2 scroll-mt-24">Open in Logo Editor</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-4">
                        Each generated logo can be opened in Netkit's built-in logo editor, where you can add text overlays, crop, and make manual adjustments before downloading the final file.
                    </p>

                    <h3 id="similar-ideas" class="text-lg font-semibold text-gray-900 dark:text-white mt-6 mb-2 scroll-mt-24">Similar Ideas</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-4">
                        After generating, a <strong>Similar Ideas</strong> section may appear below the main grid, showing other saved community logos with a similar style or prompt — useful for inspiration.
                    </p>
                </section>

                {{-- ── PRO Mode ──────────────────────────────────────────────── --}}
                <section id="pro-mode" class="mb-12 scroll-mt-24">
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white pb-3 border-b border-gray-200 dark:border-gray-800 mb-6">PRO Mode</h2>
                    <p class="text-gray-600 dark:text-gray-400 text-sm leading-relaxed mb-4">
                        Toggle <strong>★ PRO</strong> mode with the star button above the Generate button. In PRO mode, the generator uses higher-resolution pipelines and additional post-processing for sharper, more detailed outputs. The Generate button changes to a gold gradient when PRO is active.
                    </p>
                    <div class="callout callout-note not-prose">
                        <div class="flex gap-2.5">
                            <svg class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                            <p class="text-sm text-blue-800 dark:text-blue-200 m-0">PRO images cost more credits per image. The pricing breakdown updates automatically when you toggle PRO mode.</p>
                        </div>
                    </div>
                </section>

                {{-- ── Tips & Best Practices ─────────────────────────────────── --}}
                <section id="tips" class="mb-12 scroll-mt-24">
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white pb-3 border-b border-gray-200 dark:border-gray-800 mb-6">Tips & Best Practices</h2>

                    <div class="space-y-4">
                        <div class="feature-card">
                            <h4 class="font-semibold text-gray-800 dark:text-gray-200 mb-1 text-sm">Be descriptive in your prompt</h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 m-0">Instead of "a logo for my company", try "a sleek hexagonal badge with a circuit-board pattern and the letter M, dark navy and electric blue, futuristic tech style".</p>
                        </div>
                        <div class="feature-card">
                            <h4 class="font-semibold text-gray-800 dark:text-gray-200 mb-1 text-sm">Generate multiple at once</h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 m-0">Set the count to 4 or 8 to get a variety of interpretations in one generation. Compare and iterate from the one you like best using "Use Seed" or "Use as Prompt".</p>
                        </div>
                        <div class="feature-card">
                            <h4 class="font-semibold text-gray-800 dark:text-gray-200 mb-1 text-sm">Use Vector for print-ready assets</h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 m-0">When your final logo will appear on business cards, signage, or merchandise, always choose Vector (SVG) output with Flux or Recraft for infinite scalability.</p>
                        </div>
                        <div class="feature-card">
                            <h4 class="font-semibold text-gray-800 dark:text-gray-200 mb-1 text-sm">Seed-lock a design you love</h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 m-0">Found a great result? Click "Use Seed" to lock the RNG. Then tweak the prompt, palette, or background slightly to iterate without losing the core composition.</p>
                        </div>
                        <div class="feature-card">
                            <h4 class="font-semibold text-gray-800 dark:text-gray-200 mb-1 text-sm">Match palette to your brand colours</h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 m-0">Use the Custom palette option and enter your exact brand hex codes. The AI will try to incorporate them — great for brand-kit consistency.</p>
                        </div>
                    </div>
                </section>

                {{-- ── Troubleshooting ───────────────────────────────────────── --}}
                <section id="troubleshooting" class="mb-12 scroll-mt-24">
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white pb-3 border-b border-gray-200 dark:border-gray-800 mb-6">Troubleshooting</h2>

                    <div class="divide-y divide-gray-200 dark:divide-gray-800">
                        <div class="py-4">
                            <h4 class="font-semibold text-gray-800 dark:text-gray-200 text-sm mb-1">The Generate button is disabled</h4>
                            <p class="text-sm text-gray-500 dark:text-gray-400 m-0">Check that you have entered a prompt, that the domain field is filled (when Text in Logo is on), and that your credit balance is sufficient for the selected image count and model.</p>
                        </div>
                        <div class="py-4">
                            <h4 class="font-semibold text-gray-800 dark:text-gray-200 text-sm mb-1">I see "Session expired" error</h4>
                            <p class="text-sm text-gray-500 dark:text-gray-400 m-0">Your browser session has timed out. Refresh the page and log in again — your form inputs will be cleared but your credits are unaffected.</p>
                        </div>
                        <div class="py-4">
                            <h4 class="font-semibold text-gray-800 dark:text-gray-200 text-sm mb-1">Logo quality is poor or unrelated to my prompt</h4>
                            <p class="text-sm text-gray-500 dark:text-gray-400 m-0">Try a more specific prompt, switch to a different AI model, or enable PRO mode for higher resolution. Avoid very short prompts (under 5 words).</p>
                        </div>
                        <div class="py-4">
                            <h4 class="font-semibold text-gray-800 dark:text-gray-200 text-sm mb-1">Palette colours don't appear in the result</h4>
                            <p class="text-sm text-gray-500 dark:text-gray-400 m-0">Colour palettes are suggestions to the AI — not hard constraints. Results vary. If colour accuracy is critical, use the Custom palette option and also mention the colours in your prompt (e.g. "dark navy #1e3a5f and gold #d4af37").</p>
                        </div>
                        <div class="py-4">
                            <h4 class="font-semibold text-gray-800 dark:text-gray-200 text-sm mb-1">The sidebar options are missing / I cannot see Balance, Style, Palette, Shape, or Background</h4>
                            <p class="text-sm text-gray-500 dark:text-gray-400 m-0">These controls are only visible to logged-in users. Click Log In at the top of the page and sign into your account.</p>
                        </div>
                    </div>
                </section>

                {{-- ── Footer nav ───────────────────────────────────────────── --}}
                <div class="flex items-center justify-between pt-8 border-t border-gray-200 dark:border-gray-800 mt-4">
                    <div></div>
                    <a href="{{ route('domainSearch.logoGenerator') }}" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold transition">
                        Try the Logo Generator
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>
                </div>
            </div>
        </main>

        {{-- Right TOC Sidebar (desktop) --}}
        <aside class="hidden xl:flex flex-col w-64 fixed top-16 bottom-0 right-0 overflow-y-auto sidebar-scroll p-6 z-30" x-data="docsToc()">
            <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-3">On this page</p>
            <nav class="space-y-1" id="toc-nav">
                <a href="#introduction"    class="toc-link block text-sm text-gray-600 dark:text-gray-400 hover:text-emerald-600 dark:hover:text-emerald-400 py-0.5 transition">Introduction</a>
                <a href="#getting-started" class="toc-link block text-sm text-gray-600 dark:text-gray-400 hover:text-emerald-600 dark:hover:text-emerald-400 py-0.5 transition">Getting Started</a>
                <a href="#the-form"        class="toc-link block text-sm text-gray-600 dark:text-gray-400 hover:text-emerald-600 dark:hover:text-emerald-400 py-0.5 transition">The Generator Form</a>
                <a href="#domain-name"     class="toc-link block text-sm text-gray-500 dark:text-gray-500 hover:text-emerald-600 dark:hover:text-emerald-400 py-0.5 pl-3 transition">Domain / Brand Name</a>
                <a href="#prompt"          class="toc-link block text-sm text-gray-500 dark:text-gray-500 hover:text-emerald-600 dark:hover:text-emerald-400 py-0.5 pl-3 transition">Logo Prompt</a>
                <a href="#text-in-logo"    class="toc-link block text-sm text-gray-500 dark:text-gray-500 hover:text-emerald-600 dark:hover:text-emerald-400 py-0.5 pl-3 transition">Text in Logo</a>
                <a href="#output-format"   class="toc-link block text-sm text-gray-500 dark:text-gray-500 hover:text-emerald-600 dark:hover:text-emerald-400 py-0.5 pl-3 transition">Output Format</a>
                <a href="#image-count"     class="toc-link block text-sm text-gray-500 dark:text-gray-500 hover:text-emerald-600 dark:hover:text-emerald-400 py-0.5 pl-3 transition">Number of Images</a>
                <a href="#seed"            class="toc-link block text-sm text-gray-500 dark:text-gray-500 hover:text-emerald-600 dark:hover:text-emerald-400 py-0.5 pl-3 transition">Seed</a>
                <a href="#ai-models"       class="toc-link block text-sm text-gray-600 dark:text-gray-400 hover:text-emerald-600 dark:hover:text-emerald-400 py-0.5 transition">AI Models</a>
                <a href="#sidebar-options" class="toc-link block text-sm text-gray-600 dark:text-gray-400 hover:text-emerald-600 dark:hover:text-emerald-400 py-0.5 transition">Sidebar Options</a>
                <a href="#balance"         class="toc-link block text-sm text-gray-500 dark:text-gray-500 hover:text-emerald-600 dark:hover:text-emerald-400 py-0.5 pl-3 transition">Credit Balance</a>
                <a href="#style"           class="toc-link block text-sm text-gray-500 dark:text-gray-500 hover:text-emerald-600 dark:hover:text-emerald-400 py-0.5 pl-3 transition">Style</a>
                <a href="#palette"         class="toc-link block text-sm text-gray-500 dark:text-gray-500 hover:text-emerald-600 dark:hover:text-emerald-400 py-0.5 pl-3 transition">Colour Palette</a>
                <a href="#shape"           class="toc-link block text-sm text-gray-500 dark:text-gray-500 hover:text-emerald-600 dark:hover:text-emerald-400 py-0.5 pl-3 transition">Shape Container</a>
                <a href="#background"      class="toc-link block text-sm text-gray-500 dark:text-gray-500 hover:text-emerald-600 dark:hover:text-emerald-400 py-0.5 pl-3 transition">Background</a>
                <a href="#pricing"         class="toc-link block text-sm text-gray-600 dark:text-gray-400 hover:text-emerald-600 dark:hover:text-emerald-400 py-0.5 transition">Pricing & Credits</a>
                <a href="#results"         class="toc-link block text-sm text-gray-600 dark:text-gray-400 hover:text-emerald-600 dark:hover:text-emerald-400 py-0.5 transition">Working with Results</a>
                <a href="#pro-mode"        class="toc-link block text-sm text-gray-600 dark:text-gray-400 hover:text-emerald-600 dark:hover:text-emerald-400 py-0.5 transition">PRO Mode</a>
                <a href="#tips"            class="toc-link block text-sm text-gray-600 dark:text-gray-400 hover:text-emerald-600 dark:hover:text-emerald-400 py-0.5 transition">Tips & Best Practices</a>
                <a href="#troubleshooting" class="toc-link block text-sm text-gray-600 dark:text-gray-400 hover:text-emerald-600 dark:hover:text-emerald-400 py-0.5 transition">Troubleshooting</a>
            </nav>
        </aside>

    </div>

    <script>
        function docsToc() {
            return {
                init() {
                    const sections = document.querySelectorAll('section[id], h2[id], h3[id]');
                    const links = document.querySelectorAll('.toc-link');
                    const observer = new IntersectionObserver((entries) => {
                        entries.forEach(entry => {
                            if (entry.isIntersecting) {
                                links.forEach(l => l.classList.remove('active'));
                                const active = document.querySelector('.toc-link[href="#' + entry.target.id + '"]');
                                if (active) active.classList.add('active');
                            }
                        });
                    }, { rootMargin: '-80px 0px -60% 0px', threshold: 0 });
                    sections.forEach(s => observer.observe(s));
                }
            }
        }
    </script>
</body>
</html>
