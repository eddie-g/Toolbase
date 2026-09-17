<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/netkit_logo_cube.svg') }}">
    <script>if(localStorage.getItem('darkMode')==='true')document.documentElement.classList.add('dark');</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Logo Lab - Netkit</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .nk-lab {
            font-family: 'Inter', 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
            font-feature-settings: 'cv11', 'ss01';
            -webkit-font-smoothing: antialiased;
        }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="nk-lab bg-white text-zinc-900 dark:bg-zinc-950 dark:text-zinc-50">
    <x-site-header />

    @php
        $tabClass = "rounded-md px-3.5 py-1.5 text-sm font-medium transition";
        $tabOn = "bg-white text-zinc-900 shadow-sm ring-1 ring-zinc-200 dark:bg-zinc-800 dark:text-zinc-50 dark:ring-zinc-700";
        $tabOff = "text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100";
        // The visual vocabulary of the page, named once.
        $card = "rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900";
        $label = "block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400";
        $chip = "rounded-lg border px-3 py-2 text-sm font-medium transition";
        $chipOn = "border-blue-600 bg-blue-50 text-blue-700 dark:border-blue-500 dark:bg-blue-500/10 dark:text-blue-300";
        $chipOff = "border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:border-zinc-700 dark:hover:bg-zinc-800";
        $btnPrimary = "inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-zinc-300 dark:disabled:bg-zinc-700";
        $btnSecondary = "inline-flex items-center justify-center gap-2 rounded-lg border border-zinc-200 bg-white px-4 py-2.5 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800";
        $input = "w-full rounded-lg border border-zinc-200 bg-white px-3.5 py-2.5 text-sm text-zinc-900 placeholder-zinc-400 transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-50 dark:placeholder-zinc-500";
    @endphp


    <main
        class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 pt-28 pb-20"
        x-data="{
            tab: @js($tab),
            show(name) {
                this.tab = name;
                const url = new URL(window.location.href);
                if (name === 'browse') url.searchParams.set('tab', 'browse'); else url.searchParams.delete('tab');
                history.replaceState(null, '', url.toString());
            }
        }"
    >
        {{-- Page header: title on the left, the two tabs on the right --}}
        <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="inline-flex items-center gap-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">
                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-blue-600 dark:bg-blue-400"></span>
                    Netkit tools
                </p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-zinc-900 sm:text-4xl dark:text-zinc-50">Logo Lab</h1>
                <p class="mt-2 max-w-xl text-sm leading-6 text-zinc-500 dark:text-zinc-400">
                    Make logos in the studio: describe a mark, pick a style and a palette, generate vector or raster. Everything you make is kept here, and what others made can be browsed with the settings behind it.
                </p>
            </div>
            <div role="tablist" aria-label="Logo Lab sections" class="inline-flex shrink-0 self-start rounded-lg border border-zinc-200 bg-zinc-100 p-1 dark:border-zinc-800 dark:bg-zinc-900">
                <button type="button" role="tab" data-tab="generate" @click="show('generate')" :aria-selected="tab === 'generate'"
                    class="{{ $tabClass }}" :class="tab === 'generate' ? '{{ $tabOn }}' : '{{ $tabOff }}'">Generate</button>
                <button type="button" role="tab" data-tab="browse" @click="show('browse')" :aria-selected="tab === 'browse'"
                    class="{{ $tabClass }}" :class="tab === 'browse' ? '{{ $tabOn }}' : '{{ $tabOff }}'">Browse logos</button>
            </div>
        </div>

        {{-- ── Generate: the studio button and the logos this account has made ── --}}
        <section x-show="tab === 'generate'" role="tabpanel" data-panel="generate" class="mt-8">
            @if ($logoUser ?? false)
            @php
                $menuItem = "flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-[13px] font-medium text-zinc-700 transition hover:bg-zinc-100 hover:text-zinc-900 focus-visible:bg-zinc-100 focus-visible:outline-none dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-zinc-50 dark:focus-visible:bg-zinc-800";
                $viewButton = "inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition";
                $viewOn = "bg-white text-zinc-900 shadow-sm ring-1 ring-zinc-200 dark:bg-zinc-800 dark:text-zinc-50 dark:ring-zinc-700";
                $viewOff = "text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100";
            @endphp

            {{-- Studio --}}
            <div class="{{ $card }} flex flex-col gap-5 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
                <div class="flex items-start gap-4">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-950">
                        <svg class="h-5 w-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" />
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-zinc-50">Logo Studio</h2>
                        <p class="mt-1 max-w-lg text-sm leading-6 text-zinc-500 dark:text-zinc-400">
                            The full generator on its own screen: prompt, model, style, palette, background, shape, count and PRO. Every logo you make there lands in the list below.
                        </p>
                    </div>
                </div>
                <a href="{{ route('domainSearch.logoStudio') }}" class="{{ $btnPrimary }} shrink-0" data-action="open-studio">
                    Open Logo Studio
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M13 7l5 5-5 5M6 12h12" /></svg>
                </a>
            </div>

            {{-- Library: one card per generated image, grid or list --}}
            <div class="mt-8" x-data="logoLibrary()">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="flex items-center text-lg font-semibold text-zinc-900 dark:text-zinc-50">
                        Your logos
                        <span class="ml-2 rounded-md bg-zinc-100 px-1.5 py-0.5 text-xs font-medium text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400" title="Generations">{{ number_format($libraryLogos->total()) }}</span>
                    </h2>
                    <div role="group" aria-label="View" class="inline-flex rounded-lg border border-zinc-200 bg-zinc-100 p-0.5 dark:border-zinc-800 dark:bg-zinc-900">
                        <button type="button" data-logos-view="grid" @click="setView('grid')" :aria-pressed="view === 'grid' ? 'true' : 'false'" title="Grid view"
                            class="{{ $viewButton }}" :class="view === 'grid' ? '{{ $viewOn }}' : '{{ $viewOff }}'">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" /></svg>
                            Grid
                        </button>
                        <button type="button" data-logos-view="list" @click="setView('list')" :aria-pressed="view === 'list' ? 'true' : 'false'" title="List view"
                            class="{{ $viewButton }}" :class="view === 'list' ? '{{ $viewOn }}' : '{{ $viewOff }}'">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
                            List
                        </button>
                    </div>
                </div>

                @if ($libraryItems->isEmpty())
                    <div class="mt-4 rounded-xl border border-dashed border-zinc-300 bg-zinc-50 px-6 py-16 text-center dark:border-zinc-700 dark:bg-zinc-950" data-library-empty>
                        <h3 class="text-base font-semibold text-zinc-900 dark:text-zinc-50">Nothing here yet</h3>
                        <p class="mx-auto mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">Open the studio, describe a mark and generate. Every logo you make shows up here, ready to download or make more of.</p>
                        <a href="{{ route('domainSearch.logoStudio') }}" class="{{ $btnPrimary }} mt-5">Open Logo Studio</a>
                    </div>
                @else
                    <div id="logo-library" class="mt-4 grid gap-4" :class="view === 'list' ? 'grid-cols-1 gap-2' : 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4'">
                        @foreach ($libraryItems as $item)
                            @php
                                $title = $item['domain'] ?: ($item['prompt'] ?: 'Untitled');
                                $itemJson = json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                $styleLabel = ucfirst(str_replace('_', ' ', (string) preg_replace('/_pro$/', '', (string) $item['style'])));
                            @endphp
                            <div class="{{ $card }} group relative transition hover:border-zinc-300 dark:hover:border-zinc-700" data-library-item
                                :class="view === 'list' ? 'flex items-center gap-4 pr-14' : ''"
                                x-data="{ menuOpen: false }" @keydown.escape.window="menuOpen = false">
                                <div class="overflow-hidden bg-white dark:bg-zinc-100" :class="view === 'list' ? 'm-2 h-16 w-16 shrink-0 rounded-md border border-zinc-200' : 'aspect-square rounded-t-xl border-b border-zinc-200 dark:border-zinc-800'">
                                    <img src="{{ $item['preview_url'] }}" alt="{{ $title }}" class="h-full w-full object-contain" :class="view === 'list' ? 'p-1' : 'p-4'" loading="lazy">
                                </div>
                                <div class="min-w-0" :class="view === 'list' ? 'flex-1 py-3' : 'p-3'">
                                    <p class="truncate text-sm font-semibold text-zinc-900 dark:text-zinc-50" title="{{ $title }}">{{ $title }}</p>
                                    <p class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                        <span class="rounded-md bg-zinc-100 px-1.5 py-0.5 font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $item['model_name'] }}</span>
                                        @if ($styleLabel !== '')
                                            <span class="rounded-md bg-zinc-100 px-1.5 py-0.5 font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $styleLabel }}</span>
                                        @endif
                                        <span>{{ $item['created_diff'] }}</span>
                                    </p>
                                </div>
                                <div class="absolute" :class="[view === 'list' ? 'right-3 top-1/2 -translate-y-1/2' : 'right-2 top-2', menuOpen ? 'z-30' : 'z-10']" @click.away="menuOpen = false">
                                    <button type="button" @click="menuOpen = !menuOpen" :aria-expanded="menuOpen ? 'true' : 'false'" aria-haspopup="menu" aria-label="More options for {{ $title }}"
                                        class="grid h-8 w-8 place-items-center rounded-md border border-zinc-200 bg-white/95 text-zinc-500 shadow-sm transition hover:text-zinc-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:border-zinc-700 dark:bg-zinc-900/95 dark:text-zinc-400 dark:hover:text-zinc-100">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="19" cy="12" r="1.8" /></svg>
                                    </button>
                                    <ul role="menu" x-show="menuOpen" x-cloak x-transition.opacity.duration.120ms
                                        class="absolute right-0 top-[calc(100%+6px)] z-30 m-0 min-w-[200px] list-none rounded-lg border border-zinc-200 bg-white p-1 shadow-lg dark:border-zinc-800 dark:bg-zinc-900">
                                        <li role="none">
                                            <button type="button" role="menuitem" class="{{ $menuItem }}" data-action="make-more" @click="menuOpen = false; openInStudio({{ $itemJson }})">
                                                <svg class="h-4 w-4 text-zinc-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v3m0 12v3M3 12h3m12 0h3M5.6 5.6l2.1 2.1m8.6 8.6 2.1 2.1M5.6 18.4l2.1-2.1m8.6-8.6 2.1-2.1" /></svg>
                                                Make more like this
                                            </button>
                                        </li>
                                        <li role="none">
                                            <a href="{{ $item['original_url'] }}" download role="menuitem" class="{{ $menuItem }}">
                                                <svg class="h-4 w-4 text-zinc-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" /></svg>
                                                Download
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-8">
                        {{ $libraryLogos->links() }}
                    </div>
                @endif
            </div>
            @else
            {{-- Signed-out: the studio needs an account, the showcase does not --}}
            <div class="{{ $card }} mx-auto max-w-md px-6 py-12 text-center" data-login-gate>
                <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl border border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-950">
                    <svg class="h-6 w-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                    </svg>
                </div>
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-50">Sign in to generate</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Generating logos uses credits on your account. Browsing what others made is open to everyone.</p>
                <div class="mt-6 flex flex-wrap items-center justify-center gap-2">
                    <a href="{{ route('login') }}" class="{{ $btnPrimary }}">Sign in</a>
                    <button type="button" @click="show('browse')" class="{{ $btnSecondary }}">Browse logos</button>
                </div>
            </div>
            @endif
        </section>

        {{-- ── Browse ───────────────────────────────────────────────────── --}}
        <section x-show="tab === 'browse'" x-cloak role="tabpanel" data-panel="browse" class="mt-8">
            @include('logos.partials.showcase')
        </section>
    </main>

    @if ($logoUser ?? false)
    <script>
        // The library: grid or list (remembered per browser), and "Make more
        // like this", which hands the studio the exact settings of a logo.
        function logoLibrary() {
            return {
                view: 'grid',
                init() {
                    try { this.view = localStorage.getItem('netkit-logos-view') === 'list' ? 'list' : 'grid'; } catch (e) {}
                },
                setView(view) {
                    this.view = view === 'list' ? 'list' : 'grid';
                    try { localStorage.setItem('netkit-logos-view', this.view); } catch (e) {}
                },
                openInStudio(item) {
                    try { sessionStorage.setItem('logo-lab:preset', JSON.stringify(item)); } catch (e) {}
                    window.location.href = '{{ route('domainSearch.logoStudio') }}';
                },
            };
        }
    </script>
    @endif
</body>
</html>
