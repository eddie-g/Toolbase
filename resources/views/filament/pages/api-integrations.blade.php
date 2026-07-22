<x-filament-panels::page>

    {{-- Header toolbar --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Live balance and connectivity status for all external API services.
            </p>
        </div>
        <div class="flex items-center gap-3">
            @if($lastRefreshed)
                <span class="text-xs text-gray-400 dark:text-gray-500">
                    Last checked {{ $lastRefreshed }}
                </span>
            @endif
            <button
                wire:click="refresh"
                wire:loading.attr="disabled"
                class="inline-flex items-center gap-2 rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 transition disabled:opacity-50 disabled:cursor-not-allowed"
            >
                <svg wire:loading.class="animate-spin" wire:target="refresh" class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                <span wire:loading.class="opacity-0" wire:target="refresh">Refresh</span>
                <span wire:loading wire:target="refresh" class="absolute">Checking…</span>
            </button>
        </div>
    </div>

    {{-- Integration cards grid --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

        {{-- ── fal.ai / Flux ── --}}
        @php $flux = $integrations['flux'] ?? []; @endphp
        <div class="relative rounded-2xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
            {{-- Accent bar --}}
            <div class="h-1 w-full" style="background: linear-gradient(90deg, #6366f1, #8b5cf6);"></div>
            <div class="p-6">
                {{-- Header row --}}
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-3">
                        {{-- Icon --}}
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl" style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);">
                            <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Flux (fal.ai)</h3>
                            <p class="text-xs text-gray-400 dark:text-gray-500">Image generation — flux-2-flex + schnell</p>
                        </div>
                    </div>
                    {{-- Status badge --}}
                    @include('filament.pages.partials.api-status-badge', ['status' => $flux['status'] ?? 'error'])
                </div>

                {{-- Balance --}}
                @if(isset($flux['balance']) && $flux['balance'] !== null)
                    <div class="mb-4 rounded-xl bg-indigo-50 dark:bg-indigo-900/20 p-4">
                        <p class="text-xs font-medium text-indigo-500 dark:text-indigo-400 uppercase tracking-wide mb-1">{{ $flux['label'] ?? 'Balance' }}</p>
                        <p class="text-3xl font-bold text-indigo-700 dark:text-indigo-300">${{ number_format($flux['balance'], 4) }}</p>
                        <p class="text-xs text-indigo-400 mt-1">USD · tracked locally</p>
                    </div>
                @elseif(!empty($flux['note']))
                    <div class="mb-4 rounded-xl bg-gray-50 dark:bg-gray-700/50 p-4">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $flux['note'] }}</p>
                    </div>
                @endif

                {{-- Extra data (Total Spent / Credited) --}}
                @if(!empty($flux['extra']))
                    <div class="grid grid-cols-2 gap-2 mb-4">
                        @foreach($flux['extra'] as $key => $val)
                            @if($val !== null)
                                <div class="rounded-lg bg-gray-50 dark:bg-gray-700/50 px-3 py-2">
                                    <p class="text-xs text-gray-400 dark:text-gray-500">{{ $key }}</p>
                                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200 mt-0.5">{{ $val }}</p>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif

                {{-- Set balance input --}}
                <div class="mb-4">
                    @if(!$showBalanceInput)
                        <button
                            wire:click="$set('showBalanceInput', true)"
                            class="text-xs text-indigo-500 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300 font-medium underline underline-offset-2 transition"
                        >
                            + Set current balance
                        </button>
                    @else
                        <div class="rounded-xl border border-indigo-200 dark:border-indigo-800 bg-indigo-50 dark:bg-indigo-900/20 p-4 space-y-3">
                            <p class="text-xs font-medium text-indigo-600 dark:text-indigo-400 uppercase tracking-wide">Set fal.ai balance</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                Check your <a href="https://fal.ai/dashboard/billing" target="_blank" class="underline hover:text-indigo-500">fal.ai dashboard</a> for your current credits and enter the amount below. All future Flux API charges will be automatically deducted from this total.
                            </p>
                            <div class="flex gap-2">
                                <div class="relative flex-1">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">$</span>
                                    <input
                                        type="number"
                                        step="0.0001"
                                        min="0"
                                        wire:model="newFalBalance"
                                        placeholder="0.0000"
                                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 pl-7 pr-3 py-2 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent outline-none"
                                    />
                                </div>
                                <button
                                    wire:click="saveManualBalance"
                                    wire:loading.attr="disabled"
                                    class="rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 transition disabled:opacity-50"
                                >
                                    Save
                                </button>
                                <button
                                    wire:click="$set('showBalanceInput', false)"
                                    class="rounded-lg border border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 text-sm px-3 py-2 hover:bg-gray-50 dark:hover:bg-gray-700 transition"
                                >
                                    ✕
                                </button>
                            </div>
                            @error('newFalBalance')
                                <p class="text-xs text-red-500">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif
                </div>

                {{-- Recent ledger transactions --}}
                @if(!empty($flux['ledger_entries']))
                    <div class="rounded-xl border border-gray-100 dark:border-gray-700 overflow-hidden">
                        <div class="px-3 py-2 bg-gray-50 dark:bg-gray-700/50 border-b border-gray-100 dark:border-gray-700">
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Recent Transactions</p>
                        </div>
                        <div class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($flux['ledger_entries'] as $entry)
                                <div class="flex items-center justify-between px-3 py-2">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span class="shrink-0 h-2 w-2 rounded-full {{ $entry['type'] === 'credit' ? 'bg-emerald-400' : 'bg-red-400' }}"></span>
                                        <div class="min-w-0">
                                            <p class="text-xs text-gray-600 dark:text-gray-300 truncate">
                                                {{ $entry['model'] ?? ($entry['type'] === 'credit' ? 'Top-up' : 'Charge') }}
                                            </p>
                                            <p class="text-xs text-gray-400 dark:text-gray-500">{{ $entry['date'] ?? '' }}</p>
                                        </div>
                                    </div>
                                    <div class="text-right shrink-0 ml-3">
                                        <p class="text-xs font-mono font-semibold {{ $entry['type'] === 'credit' ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500 dark:text-red-400' }}">
                                            {{ $entry['type'] === 'credit' ? '+' : '-' }}${{ number_format($entry['amount'], 4) }}
                                        </p>
                                        <p class="text-xs text-gray-400 font-mono">${{ number_format($entry['balance'], 4) }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Key hint --}}
                @php $falKey = config('services.fal.key'); @endphp
                @if($falKey)
                    <p class="mt-4 text-xs text-gray-300 dark:text-gray-600 font-mono">
                        Key: {{ substr($falKey, 0, 8) }}…{{ substr($falKey, -4) }}
                    </p>
                @endif
            </div>
        </div>

        {{-- ── OpenAI (ChatGPT / GPT Image) ── --}}
        @php $openai = $integrations['openai'] ?? []; @endphp
        <div class="relative rounded-2xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
            <div class="h-1 w-full" style="background: linear-gradient(90deg, #10b981, #059669);"></div>
            <div class="p-6">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-[#10a37f]">
                            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M22.282 9.821a5.985 5.985 0 0 0-.516-4.91 6.046 6.046 0 0 0-6.51-2.9A6.065 6.065 0 0 0 4.981 4.18a5.985 5.985 0 0 0-3.998 2.9 6.046 6.046 0 0 0 .743 7.097 5.98 5.98 0 0 0 .51 4.911 6.051 6.051 0 0 0 6.515 2.9A5.985 5.985 0 0 0 13.26 24a6.056 6.056 0 0 0 5.772-4.206 5.99 5.99 0 0 0 3.997-2.9 6.056 6.056 0 0 0-.747-7.073zM13.26 22.43a4.476 4.476 0 0 1-2.876-1.04l.141-.081 4.779-2.758a.795.795 0 0 0 .392-.681v-6.737l2.02 1.168a.071.071 0 0 1 .038.052v5.583a4.504 4.504 0 0 1-4.494 4.494zM3.6 18.304a4.47 4.47 0 0 1-.535-3.014l.142.085 4.783 2.759a.771.771 0 0 0 .78 0l5.843-3.369v2.332a.08.08 0 0 1-.033.062L9.74 19.95a4.5 4.5 0 0 1-6.14-1.646zM2.34 7.896a4.485 4.485 0 0 1 2.366-1.973V11.6a.766.766 0 0 0 .388.676l5.815 3.355-2.02 1.168a.076.076 0 0 1-.071 0l-4.83-2.786A4.504 4.504 0 0 1 2.34 7.872zm16.597 3.855l-5.833-3.387L15.119 7.2a.076.076 0 0 1 .071 0l4.83 2.791a4.494 4.494 0 0 1-.676 8.105v-5.678a.79.79 0 0 0-.407-.667zm2.01-3.023l-.141-.085-4.774-2.782a.776.776 0 0 0-.785 0L9.409 9.23V6.897a.066.066 0 0 1 .028-.061l4.83-2.787a4.5 4.5 0 0 1 6.68 4.66zm-12.64 4.135l-2.02-1.164a.08.08 0 0 1-.038-.057V6.075a4.5 4.5 0 0 1 7.375-3.453l-.142.08L8.704 5.46a.795.795 0 0 0-.393.681zm1.097-2.365l2.602-1.5 2.607 1.5v2.999l-2.597 1.5-2.607-1.5z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">OpenAI</h3>
                            <p class="text-xs text-gray-400 dark:text-gray-500">ChatGPT + GPT Image 1.5</p>
                        </div>
                    </div>
                    @include('filament.pages.partials.api-status-badge', ['status' => $openai['status'] ?? 'error'])
                </div>

                @if(isset($openai['balance']) && $openai['balance'] !== null)
                    <div class="mb-4 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 p-4">
                        <p class="text-xs font-medium text-emerald-500 dark:text-emerald-400 uppercase tracking-wide mb-1">{{ $openai['label'] ?? 'Credits' }}</p>
                        <p class="text-3xl font-bold text-emerald-700 dark:text-emerald-300">${{ number_format($openai['balance'], 2) }}</p>
                        <p class="text-xs text-emerald-400 mt-1">USD</p>
                    </div>
                @elseif(!empty($openai['note']))
                    <div class="mb-4 rounded-xl bg-gray-50 dark:bg-gray-700/50 p-4">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $openai['note'] }}</p>
                    </div>
                @endif

                @if(!empty($openai['extra']))
                    <div class="grid grid-cols-2 gap-2">
                        @foreach($openai['extra'] as $key => $val)
                            @if($val !== null)
                                <div class="rounded-lg bg-gray-50 dark:bg-gray-700/50 px-3 py-2">
                                    <p class="text-xs text-gray-400 dark:text-gray-500">{{ $key }}</p>
                                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200 mt-0.5">{{ $val }}</p>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif

                @php $oaiKey = config('services.openai.api_key'); @endphp
                @if($oaiKey)
                    <p class="mt-4 text-xs text-gray-300 dark:text-gray-600 font-mono">
                        Key: {{ substr($oaiKey, 0, 8) }}…{{ substr($oaiKey, -4) }}
                    </p>
                @endif
            </div>
        </div>

        {{-- ── Gemini ── --}}
        @php $gemini = $integrations['gemini'] ?? []; @endphp
        <div class="relative rounded-2xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
            <div class="h-1 w-full" style="background: linear-gradient(90deg, #4285f4, #34a853, #fbbc05, #ea4335);"></div>
            <div class="p-6">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-white dark:bg-gray-700 ring-1 ring-gray-200 dark:ring-gray-600">
                            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z" fill="#4285f4"/>
                                <path d="M12 2v10l8.66 5C19.07 20.07 15.84 22 12 22 6.48 22 2 17.52 2 12 2 7.3 5.28 3.35 9.74 2.28L12 2z" fill="#34a853"/>
                                <path d="M12 12L2.28 9.74A10.015 10.015 0 0 0 2 12c0 4.7 3.28 8.65 7.74 9.72L12 12z" fill="#fbbc05"/>
                                <path d="M12 2L9.74 2.28C5.28 3.35 2 7.3 2 12h10L12 2z" fill="#ea4335"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Google Gemini</h3>
                            <p class="text-xs text-gray-400 dark:text-gray-500">Vision, text generation, logo description</p>
                        </div>
                    </div>
                    @include('filament.pages.partials.api-status-badge', ['status' => $gemini['status'] ?? 'error'])
                </div>

                @if(!empty($gemini['note']))
                    <div class="mb-4 rounded-xl bg-blue-50 dark:bg-blue-900/20 p-4">
                        <p class="text-sm text-blue-600 dark:text-blue-400">{{ $gemini['note'] }}</p>
                    </div>
                @endif

                @if(!empty($gemini['extra']))
                    <div class="grid grid-cols-2 gap-2">
                        @foreach($gemini['extra'] as $key => $val)
                            @if($val !== null)
                                <div class="rounded-lg bg-gray-50 dark:bg-gray-700/50 px-3 py-2">
                                    <p class="text-xs text-gray-400 dark:text-gray-500">{{ $key }}</p>
                                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200 mt-0.5">{{ $val }}</p>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif

                @php $gKey = config('services.gemini.api_key'); @endphp
                @if($gKey)
                    <p class="mt-4 text-xs text-gray-300 dark:text-gray-600 font-mono">
                        Key: {{ substr($gKey, 0, 8) }}…{{ substr($gKey, -4) }}
                    </p>
                @endif
            </div>
        </div>

        {{-- ── Recraft ── --}}
        @php $recraft = $integrations['recraft'] ?? []; @endphp
        <div class="relative rounded-2xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
            <div class="h-1 w-full" style="background: linear-gradient(90deg, #f59e0b, #ef4444);"></div>
            <div class="p-6">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl" style="background: linear-gradient(135deg, #f59e0b 0%, #ef4444 100%);">
                            <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Recraft</h3>
                            <p class="text-xs text-gray-400 dark:text-gray-500">Vector & raster logo generation</p>
                        </div>
                    </div>
                    @include('filament.pages.partials.api-status-badge', ['status' => $recraft['status'] ?? 'error'])
                </div>

                @if(isset($recraft['balance']) && $recraft['balance'] !== null)
                    <div class="mb-4 rounded-xl bg-amber-50 dark:bg-amber-900/20 p-4">
                        <p class="text-xs font-medium text-amber-500 dark:text-amber-400 uppercase tracking-wide mb-1">{{ $recraft['label'] ?? 'Credits' }}</p>
                        <p class="text-3xl font-bold text-amber-700 dark:text-amber-300">{{ number_format($recraft['balance'], 0) }}</p>
                        <p class="text-xs text-amber-400 mt-1">credits</p>
                    </div>
                @elseif(!empty($recraft['note']))
                    <div class="mb-4 rounded-xl bg-gray-50 dark:bg-gray-700/50 p-4">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $recraft['note'] }}</p>
                    </div>
                @endif

                @if(!empty($recraft['extra']))
                    <div class="grid grid-cols-2 gap-2">
                        @foreach($recraft['extra'] as $key => $val)
                            @if($val !== null)
                                <div class="rounded-lg bg-gray-50 dark:bg-gray-700/50 px-3 py-2">
                                    <p class="text-xs text-gray-400 dark:text-gray-500">{{ $key }}</p>
                                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200 mt-0.5 truncate">{{ $val }}</p>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif

                @php $rKey = config('services.recraft.key'); @endphp
                @if($rKey)
                    <p class="mt-4 text-xs text-gray-300 dark:text-gray-600 font-mono">
                        Key: {{ substr($rKey, 0, 8) }}…{{ substr($rKey, -4) }}
                    </p>
                @endif
            </div>
        </div>

        {{-- ── Adobe PDF Services ── --}}
        @php $adobe = $integrations['adobe'] ?? []; @endphp
        <div class="relative rounded-2xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden md:col-span-2">
            <div class="h-1 w-full" style="background: linear-gradient(90deg, #fa0f00, #b30b00);"></div>
            <div class="p-6">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-[#fa0f00]">
                            <span class="text-xl font-bold text-white">A</span>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Adobe PDF Services</h3>
                            <p class="text-xs text-gray-400 dark:text-gray-500">High-fidelity PDF export to Word and Excel</p>
                        </div>
                    </div>
                    @include('filament.pages.partials.api-status-badge', ['status' => $adobe['status'] ?? 'error'])
                </div>

                @if(!empty($adobe['note']))
                    <div class="mb-4 rounded-xl bg-red-50 dark:bg-red-900/20 p-4">
                        <p class="text-sm text-red-700 dark:text-red-300">{{ $adobe['note'] }}</p>
                    </div>
                @endif

                @if(!empty($adobe['extra']))
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mb-5">
                        @foreach($adobe['extra'] as $key => $val)
                            <div class="rounded-lg bg-gray-50 dark:bg-gray-700/50 px-3 py-2">
                                <p class="text-xs text-gray-400 dark:text-gray-500">{{ $key }}</p>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200 mt-0.5">{{ $val }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-sm font-semibold text-gray-900 dark:text-white mb-3">Export provider routing</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <label class="block">
                            <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1.5">Word exports</span>
                            <select wire:model="wordConversionProvider" class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-white focus:border-red-500 focus:ring-red-500">
                                <option value="local">Local converter</option>
                                <option value="adobe">Adobe PDF Services</option>
                            </select>
                            @error('wordConversionProvider') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        </label>

                        <label class="block">
                            <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1.5">Excel exports</span>
                            <select wire:model="excelConversionProvider" class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-white focus:border-red-500 focus:ring-red-500">
                                <option value="local">Local converter</option>
                                <option value="adobe">Adobe PDF Services</option>
                            </select>
                            @error('excelConversionProvider') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        </label>
                    </div>

                    <label class="mt-4 flex items-start gap-3">
                        <input type="checkbox" wire:model="fallbackToLocal" class="mt-0.5 rounded border-gray-300 text-red-600 focus:ring-red-500">
                        <span>
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-200">Fall back to the local converter if Adobe fails</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">Keeps exports available during Adobe errors, timeouts, or quota limits.</span>
                        </span>
                    </label>

                    <div class="mt-4 flex justify-end">
                        <button
                            wire:click="saveDocumentConversionSettings"
                            wire:loading.attr="disabled"
                            wire:target="saveDocumentConversionSettings"
                            class="rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-medium px-4 py-2 transition disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="saveDocumentConversionSettings">Save conversion settings</span>
                            <span wire:loading wire:target="saveDocumentConversionSettings">Saving…</span>
                        </button>
                    </div>
                </div>

                @php $adobeClientId = config('services.adobe_pdf_services.client_id'); @endphp
                @if($adobeClientId)
                    <p class="mt-4 text-xs text-gray-300 dark:text-gray-600 font-mono">
                        Client ID: {{ substr($adobeClientId, 0, 8) }}…{{ substr($adobeClientId, -4) }}
                    </p>
                @endif
            </div>
        </div>

    </div>{{-- /grid --}}

</x-filament-panels::page>
