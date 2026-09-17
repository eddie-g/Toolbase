{{-- The Browse logos tab of the Logo Lab: the public showcase. Expects the LogoShowcase::browse() view data. --}}
    @php
        $modelColor = fn(string $m) => match(true) {
            str_contains($m, 'flux-pro')    => 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
            str_contains($m, 'flux')        => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
            str_contains($m, 'recraft')     => 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
            str_contains($m, 'gpt-image'),
            str_contains($m, 'dall-e')      => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
            default                         => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300',
        };
        $styleColor = 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300';
    @endphp

    <div
        class="mt-2"
        x-data="{
            selected: null,
            open(item) { this.selected = item; document.body.style.overflow = 'hidden'; },
            close() { this.selected = null; document.body.style.overflow = ''; },
            makeYourOwn(item) {
                // The studio is its own page: leave it the exact prompt and
                // settings to pick up, then go there. Signed out, sign in
                // first; the preset waits in the browser for that visit.
                const preset = { ...item };
                this.close();
                try { sessionStorage.setItem('logo-lab:preset', JSON.stringify(preset)); } catch (e) {}
                window.location.href = document.querySelector('[data-login-gate]') ? '{{ route('login') }}' : '{{ route('domainSearch.logoStudio') }}';
            }
        }"
        @keydown.escape.window="close()"
    >
        <p class="mb-4 text-sm text-zinc-500 dark:text-zinc-400">
            {{ number_format($showcaseCount) }} logos made here, with the exact settings behind each one. Click any to see how it was made.
        </p>

        {{-- Search + Filters --}}
        <form method="GET" action="{{ route('domainSearch.logoGenerator') }}" class="flex flex-col sm:flex-row gap-3 mb-8">
            <input type="hidden" name="tab" value="browse" />
            <div class="flex-1 relative">
                <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input
                    type="text"
                    name="search"
                    value="{{ $search }}"
                    placeholder="Search by brand or description..."
                    class="w-full pl-10 pr-4 py-2.5 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 text-sm text-zinc-900 dark:text-zinc-100 placeholder-zinc-400 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                />
            </div>
            <select name="style" onchange="this.form.submit()"
                class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 text-sm text-zinc-700 dark:text-zinc-300 px-3 py-2.5 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <option value="">All Styles</option>
                @foreach($styles as $s)
                    <option value="{{ $s }}" @selected($filterStyle === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
            <select name="model" onchange="this.form.submit()"
                class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 text-sm text-zinc-700 dark:text-zinc-300 px-3 py-2.5 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <option value="">All Models</option>
                @foreach($models as $m)
                    <option value="{{ $m }}" @selected($filterModel === $m)>{{ \App\Services\LogoShowcase::codeName($m) }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-5 py-2.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold transition-colors">
                Search
            </button>
            @if($search !== '' || $filterStyle !== '' || $filterModel !== '')
                <a href="{{ route('domainSearch.logoGenerator', ['tab' => 'browse']) }}" class="px-4 py-2.5 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:bg-zinc-50 dark:hover:bg-zinc-800 text-zinc-600 dark:text-zinc-400 text-sm font-medium transition-colors flex items-center">
                    Clear
                </a>
            @endif
        </form>

        {{-- Grid --}}
        @if($items->isEmpty())
            <div class="text-center py-32">
                <div class="w-16 h-16 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center mx-auto mb-4">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">No logos found</h3>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    @if($search !== '' || $filterStyle !== '' || $filterModel !== '')
                        Nothing matched your filters — <a href="{{ route('domainSearch.logoGenerator', ['tab' => 'browse']) }}" class="text-blue-600 dark:text-blue-400 underline">clear them</a>
                    @else
                        Check back soon — the showcase is curated regularly.
                    @endif
                </p>
            </div>
        @else
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5 gap-4">
                @foreach($items as $item)
                    @php
                        $itemJson = json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                    @endphp
                    <div
                        @click="open({{ $itemJson }})"
                        class="group relative rounded-lg overflow-hidden bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700 transition-colors duration-200 cursor-pointer"
                    >
                        {{-- Image --}}
                        <div class="aspect-square bg-white dark:bg-zinc-100 flex items-center justify-center overflow-hidden">
                            <img
                                src="{{ $item['url'] }}"
                                alt="{{ $item['domain'] }} — {{ $item['style'] }}"
                                class="w-full h-full object-contain p-3 group-hover:scale-105 transition-transform duration-500"
                                loading="lazy"
                                onerror="this.parentElement.innerHTML='<div class=\'flex items-center justify-center w-full h-full text-zinc-400 text-xs text-center p-4\'>Image unavailable</div>'"
                            />
                        </div>

                        {{-- Hover overlay --}}
                        <div class="absolute inset-0 bg-black/0 group-hover:bg-black/25 transition-all duration-300 flex items-center justify-center">
                            <div class="opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                                <div class="bg-white/90 dark:bg-zinc-900/90 backdrop-blur-sm rounded-lg px-3 py-2 flex items-center gap-1.5 shadow-lg">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 text-zinc-700 dark:text-zinc-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    <span class="text-xs font-semibold text-zinc-700 dark:text-zinc-200">View Details</span>
                                </div>
                            </div>
                        </div>

                        {{-- Footer --}}
                        <div class="p-3 border-t border-zinc-100 dark:border-zinc-800">
                            <p class="text-xs font-semibold text-zinc-800 dark:text-zinc-200 truncate mb-1.5" title="{{ $item['domain'] }}">
                                {{ $item['domain'] ?: '—' }}
                            </p>
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-md text-[10px] font-semibold {{ $modelColor($item['model']) }}">
                                    {{ $item['model_name'] }}
                                </span>
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-md text-[10px] font-medium {{ $styleColor }}">
                                    {{ ucfirst($item['style']) }}
                                </span>
                            </div>
                            <p class="text-[10px] text-zinc-400 dark:text-zinc-500 mt-1.5">{{ $item['created_diff'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Pagination --}}
            <div class="mt-10">
                {{ $logos->links() }}
            </div>
        @endif

        {{-- Detail Modal --}}
        <template x-if="selected !== null">
            <div class="fixed inset-0 z-50 flex items-center justify-center p-4" @click.self="close()">
                {{-- Backdrop --}}
                <div class="absolute inset-0 bg-zinc-950/70 backdrop-blur-sm" @click="close()"></div>

                {{-- Card --}}
                <div class="relative bg-white dark:bg-zinc-900 rounded-2xl shadow-xl border border-zinc-200 dark:border-zinc-800 w-full max-w-5xl max-h-[92vh] overflow-hidden flex flex-col md:flex-row">

                    {{-- Close --}}
                    <button
                        @click="close()"
                        class="absolute top-4 right-4 z-10 w-9 h-9 rounded-full bg-black/10 hover:bg-black/20 dark:bg-white/10 dark:hover:bg-white/20 flex items-center justify-center transition-colors"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-zinc-700 dark:text-zinc-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>

                    {{-- Image Panel --}}
                    <div class="md:w-5/12 bg-white dark:bg-zinc-100 border-b md:border-b-0 md:border-r border-zinc-200 dark:border-zinc-800 flex items-center justify-center p-6 md:p-10 min-h-64 md:min-h-0 shrink-0">
                        <img
                            :src="selected.url"
                            :alt="selected.domain"
                            class="max-w-full max-h-[360px] md:max-h-full object-contain rounded-lg"
                        />
                    </div>

                    {{-- Parameters Panel --}}
                    <div class="md:w-7/12 overflow-y-auto flex flex-col divide-y divide-zinc-100 dark:divide-zinc-800">

                        {{-- Header --}}
                        <div class="p-6 pb-4">
                            <div class="flex items-center gap-2 flex-wrap mb-2">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-bold bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300"
                                    x-text="selected.model_name"></span>
                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                                    x-text="selected.style ? selected.style.charAt(0).toUpperCase() + selected.style.slice(1) : ''"></span>
                            </div>
                            <h2 class="text-xl font-bold text-zinc-900 dark:text-zinc-100" x-text="selected.domain || 'Untitled'"></h2>
                            <p class="text-xs text-zinc-400 dark:text-zinc-500 mt-0.5" x-text="'Generated ' + selected.created_at"></p>
                        </div>

                        {{-- Params --}}
                        <div class="p-6 space-y-5">
                            <p class="text-[10px] font-semibold uppercase tracking-widest text-zinc-400 dark:text-zinc-500">Generation Parameters</p>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mb-0.5">Model</p>
                                    <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200" x-text="selected.model_name"></p>
                                </div>
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mb-0.5">Style</p>
                                    <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200 capitalize" x-text="selected.style"></p>
                                </div>
                                <template x-if="selected.width && selected.height">
                                    <div>
                                        <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mb-0.5">Resolution</p>
                                        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200" x-text="selected.width + ' × ' + selected.height"></p>
                                    </div>
                                </template>
                                <template x-if="selected.bg_color">
                                    <div>
                                        <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mb-0.5">Background</p>
                                        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200" x-text="selected.bg_color"></p>
                                    </div>
                                </template>
                                <template x-if="selected.seed_number">
                                    <div>
                                        <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mb-0.5">Seed</p>
                                        <p class="text-sm font-mono font-medium text-zinc-800 dark:text-zinc-200" x-text="selected.seed_number"></p>
                                    </div>
                                </template>
                                <template x-if="selected.response_time_ms">
                                    <div>
                                        <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mb-0.5">Generation Time</p>
                                        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200"
                                            x-text="selected.response_time_ms >= 1000 ? (selected.response_time_ms / 1000).toFixed(1) + 's' : selected.response_time_ms + 'ms'"></p>
                                    </div>
                                </template>
                            </div>


                        </div>

                        {{-- Actions --}}
                        <div class="p-6 pt-4 flex items-center gap-3">
                            <a
                                :href="selected.url"
                                download
                                class="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold transition-colors shadow-sm"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                </svg>
                                Download
                            </a>
                            <button type="button" @click="makeYourOwn(selected)" data-action="make-your-own"
                                class="px-4 py-2.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold transition-colors">
                                Make your own
                            </button>
                            <button
                                @click="close()"
                                class="px-4 py-2.5 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:bg-zinc-50 dark:hover:bg-zinc-800 text-zinc-700 dark:text-zinc-300 text-sm font-medium transition-colors"
                            >
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
