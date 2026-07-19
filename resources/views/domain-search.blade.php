<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/netkit_logo_cube.svg') }}">
    <script>if(localStorage.getItem('darkMode')==='true')document.documentElement.classList.add('dark');</script>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>Domain Search - Netkit</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        [x-cloak] { display: none !important; }
        .result-enter { animation: resultSlide 0.2s ease-out; }
        @keyframes resultSlide {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .progress-bar { transition: width 0.3s ease; }
    </style>
</head>
@php($isDomainSearchLoggedIn = auth()->check() || auth('admin')->check())
<body class="bg-gray-50 dark:bg-gray-950 antialiased min-h-screen">

    <!-- Header -->
    <x-site-header />

    <!-- Main Content -->
    <main class="pt-28 pb-20" x-data="domainSearch()">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8 max-w-[1000px]">

            <!-- Title -->
            <div class="text-center mb-10">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-100 dark:bg-blue-900/40 rounded-2xl mb-5">
                    <svg class="w-8 h-8 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path>
                    </svg>
                </div>
                <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 dark:text-white mb-3">Domain Search</h1>
                <p class="text-gray-500 dark:text-gray-400 text-lg">Check availability or generate creative domain ideas</p>
                <div class="mt-4">
                    <a href="/domain-search/faq" class="inline-flex items-center gap-2 text-sm text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition font-medium">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Privacy & FAQ
                    </a>
                </div>
            </div>

            <!-- Mode Tabs -->
            <div class="flex bg-gray-100 dark:bg-gray-800 rounded-xl p-1 mb-8">
                <button
                    @click="setMode('direct')"
                    class="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium transition-all"
                    :class="mode === 'direct'
                        ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm'
                        : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    Check Domains
                </button>
                <button
                    @click="setMode('generate')"
                    class="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium transition-all"
                    :class="mode === 'generate'
                        ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm'
                        : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                    </svg>
                    Generate Ideas
                </button>
                <button
                    @click="setMode('ai')"
                    class="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium transition-all"
                    :class="mode === 'ai'
                        ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm'
                        : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                    </svg>
                    AI Generator
                </button>
            </div>

            <!-- Search Form Card -->
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-lg shadow-gray-200/50 dark:shadow-black/20 border border-gray-200 dark:border-gray-800 p-6 mb-8">

                <!-- Direct Check Mode -->
                <form x-show="mode === 'direct'" @submit.prevent="searchDirect()">
                    <div class="mb-5">
                        <div class="mb-2">
                            <label for="domain-input" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Domain Names</label>
                        </div>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </div>
                            <input
                                id="domain-input"
                                type="text"
                                x-model="query"
                                @keydown.enter.prevent="searchDirect()"
                                placeholder="e.g.  coolstartup, myapp, brandname"
                                class="w-full pl-12 pr-4 py-3.5 rounded-xl border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition text-base"
                                autofocus
                            />
                        </div>
                        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">Separate multiple names with commas or spaces. No TLD needed.</p>

                        <!-- File Upload Drop Zone -->
                        <div class="mt-3">
                            <!-- Loaded state -->
                            <template x-if="fileNames.length > 0">
                                <div class="flex items-center justify-between gap-3 px-4 py-3 rounded-xl border border-emerald-300 dark:border-emerald-700 bg-emerald-50 dark:bg-emerald-900/20">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v8"></path></svg>
                                        <span class="text-sm text-emerald-700 dark:text-emerald-300 font-medium truncate">
                                            <span x-text="fileNames.length"></span> names loaded
                                            <span class="font-normal opacity-70" x-text="fileNameLoaded ? 'from ' + fileNameLoaded : ''"></span>
                                        </span>
                                    </div>
                                    <button type="button" @click="fileNames = []; fileNameLoaded = ''; query = ''"
                                        class="flex-shrink-0 text-xs text-emerald-600 dark:text-emerald-400 hover:text-red-500 dark:hover:text-red-400 transition font-medium">
                                        Clear
                                    </button>
                                </div>
                            </template>

                            <!-- Drop zone -->
                            <template x-if="fileNames.length === 0">
                                <label
                                    class="flex flex-col items-center gap-2 w-full cursor-pointer rounded-xl border-2 border-dashed px-4 py-5 transition"
                                    :class="fileDragOver
                                        ? 'border-blue-400 bg-blue-50 dark:bg-blue-900/20'
                                        : 'border-gray-300 dark:border-gray-700 hover:border-blue-400 dark:hover:border-blue-600'"
                                    @dragover.prevent="fileDragOver = true"
                                    @dragleave.prevent="fileDragOver = false"
                                    @drop.prevent="fileDragOver = false; handleFileUpload($event.dataTransfer.files[0])">
                                    <svg class="w-7 h-7 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v8"></path></svg>
                                    <span class="text-sm text-gray-500 dark:text-gray-400">Drag &amp; drop a <strong>.csv</strong> or <strong>.txt</strong> file, or <span class="text-blue-600 dark:text-blue-400">click to browse</span></span>
                                    <span class="text-xs text-gray-400 dark:text-gray-500">Up to 100 domain names — one per line or comma-separated</span>
                                    <input type="file" class="hidden" accept=".csv,.txt,text/csv,text/plain"
                                        @change="handleFileUpload($event.target.files[0]); $event.target.value = ''" />
                                </label>
                            </template>

                            <!-- Parse error -->
                            <p x-show="fileUploadError" x-cloak class="mt-1.5 text-xs text-red-500" x-text="fileUploadError"></p>

                            <!-- Guest upload remaining count -->
                            <p x-show="!isLoggedIn && !fileUploadError" x-cloak class="mt-1.5 text-xs text-gray-400 dark:text-gray-500">
                                <span x-text="remainingFileUploads"></span> of 5 free file uploads remaining today &mdash;
                                <a href="/login" class="text-blue-500 hover:underline">log in</a> for unlimited
                            </p>
                        </div>
                    </div>

                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Extensions</label>
                        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-3"
                             @click.outside="closeTldTypeaheadFor('direct')">
                            <div class="flex flex-wrap gap-2 mb-2" x-show="tlds.length > 0">
                                <template x-for="tld in tlds" :key="'direct-' + tld">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold"
                                          :class="tldBadgeClass('.' + tld)">
                                        <span x-text="'.' + tld"></span>
                                        <button type="button" class="leading-none opacity-80 hover:opacity-100"
                                                @click.prevent="removeTld(tld)">x</button>
                                    </span>
                                </template>
                            </div>
                            <div class="relative">
                                <input
                                    type="text"
                                    x-model="tldQuery"
                                    @focus="openTldTypeahead()"
                                    @input="updateTldSuggestions()"
                                    @keydown.enter.prevent="selectFirstTldSuggestion()"
                                    @keydown.escape.prevent="closeTldTypeahead()"
                                    placeholder="Type to add extensions (e.g. .io, .co, .xyz)"
                                    class="w-full px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition"
                                />
                                <div x-show="tldOpen && tldSuggestions.length > 0" x-cloak
                                     class="absolute z-[9999] mt-1 w-full max-h-56 overflow-auto rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-lg">
                                    <template x-for="option in tldSuggestions" :key="'direct-opt-' + option.tld">
                                        <button type="button"
                                                @click.prevent="addTld(option.tld)"
                                                class="w-full px-3 py-2 text-left text-sm text-gray-900 dark:text-gray-100 hover:bg-blue-50 dark:hover:bg-gray-800 flex items-center justify-between">
                                            <span class="font-semibold text-gray-900 dark:text-white" x-text="'.' + option.tld"></span>
                                            <span class="text-xs text-gray-600 dark:text-gray-300" x-text="option.popularity ? ('#' + option.popularity) : ''"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" :disabled="loading || (!query.trim() && fileNames.length === 0) || tlds.length === 0"
                        class="w-full py-3.5 px-6 rounded-xl bg-blue-600 hover:bg-blue-700 disabled:bg-gray-300 dark:disabled:bg-gray-700 disabled:cursor-not-allowed text-white font-semibold text-base transition flex items-center justify-center gap-2">
                        <template x-if="loading">
                            <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </template>
                        <span x-text="loading ? 'Checking...' : 'Check Availability'"></span>
                    </button>
                    
                    <div class="mt-3 text-center">
                        <p class="text-xs text-gray-500 dark:text-gray-400 flex items-center justify-center gap-1.5">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                            </svg>
                            <span>Your searches are never logged or tracked</span>
                        </p>
                    </div>
                </form>

                <!-- Generate Mode -->
                <form x-show="mode === 'generate'" @submit.prevent="searchGenerate()">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                        <div>
                            <label for="prefix-input" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Prefix Word</label>
                            <input
                                id="prefix-input"
                                type="text"
                                x-model="prefixWord"
                                @keydown.enter.prevent="searchGenerate()"
                                placeholder="e.g. star, neo, ultra"
                                class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition text-base"
                            />
                        </div>
                        <div>
                            <label for="suffix-input" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Suffix Word</label>
                            <input
                                id="suffix-input"
                                type="text"
                                x-model="suffixWord"
                                @keydown.enter.prevent="searchGenerate()"
                                placeholder="e.g. labs, zone, forge"
                                class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition text-base"
                            />
                        </div>
                    </div>

                    <div class="mb-5">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Dictionary Category</label>
                        <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
                            <template x-for="cat in categoryOptions" :key="cat.value">
                                <button type="button"
                                    @click="selectedCategory = cat.value"
                                    class="px-3 py-2 text-sm rounded-lg border transition capitalize"
                                    :class="selectedCategory === cat.value
                                        ? 'bg-purple-100 dark:bg-purple-900/30 border-purple-300 dark:border-purple-600 text-purple-700 dark:text-purple-300'
                                        : 'bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-600'"
                                    x-text="cat.label">
                                </button>
                            </template>
                        </div>
                    </div>

                    <div class="mb-5">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Total Name Length
                            <span class="ml-1 font-normal text-gray-400 dark:text-gray-500" x-text="(function(){
                                const pre = prefixWord.trim().replace(/[^a-zA-Z0-9-]/g,'').length;
                                const suf = suffixWord.trim().replace(/[^a-zA-Z0-9-]/g,'').length;
                                const affix = pre + suf;
                                let s = '(' + wordMinLength + '\u2013' + wordMaxLength + ' letters total';
                                if (affix > 0) {
                                    s += ', core word: ' + Math.max(1, wordMinLength - affix) + '\u2013' + Math.max(1, wordMaxLength - affix);
                                }
                                return s + ')';
                            })()"></span>
                        </label>
                        <div class="flex items-center gap-4">
                            <div class="flex items-center gap-2 flex-1">
                                <span class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap w-6">Min</span>
                                <input type="range" x-model.number="wordMinLength"
                                    min="3" max="14" step="1"
                                    @input="if (wordMinLength > wordMaxLength) wordMaxLength = wordMinLength"
                                    class="flex-1 accent-purple-600 cursor-pointer" />
                                <span class="w-5 text-center text-sm font-semibold text-gray-700 dark:text-gray-300" x-text="wordMinLength"></span>
                            </div>
                            <div class="flex items-center gap-2 flex-1">
                                <span class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap w-6">Max</span>
                                <input type="range" x-model.number="wordMaxLength"
                                    min="3" max="14" step="1"
                                    @input="if (wordMaxLength < wordMinLength) wordMinLength = wordMaxLength"
                                    class="flex-1 accent-purple-600 cursor-pointer" />
                                <span class="w-5 text-center text-sm font-semibold text-gray-700 dark:text-gray-300" x-text="wordMaxLength"></span>
                            </div>
                        </div>
                    </div>

                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Extensions</label>
                        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-3"
                             @click.outside="closeTldTypeaheadFor('generate')">
                            <div class="flex flex-wrap gap-2 mb-2" x-show="tlds.length > 0">
                                <template x-for="tld in tlds" :key="'gen-' + tld">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold"
                                          :class="tldBadgeClass('.' + tld)">
                                        <span x-text="'.' + tld"></span>
                                        <button type="button" class="leading-none opacity-80 hover:opacity-100"
                                                @click.prevent="removeTld(tld)">x</button>
                                    </span>
                                </template>
                            </div>
                            <div class="relative">
                                <input
                                    type="text"
                                    x-model="tldQuery"
                                    @focus="openTldTypeahead()"
                                    @input="updateTldSuggestions()"
                                    @keydown.enter.prevent="selectFirstTldSuggestion()"
                                    @keydown.escape.prevent="closeTldTypeahead()"
                                    placeholder="Type to add extensions (e.g. .io, .co, .xyz)"
                                    class="w-full px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition"
                                />
                                <div x-show="tldOpen && tldSuggestions.length > 0" x-cloak
                                     class="absolute z-[9999] mt-1 w-full max-h-56 overflow-auto rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-lg">
                                    <template x-for="option in tldSuggestions" :key="'gen-opt-' + option.tld">
                                        <button type="button"
                                                @click.prevent="addTld(option.tld)"
                                                class="w-full px-3 py-2 text-left text-sm text-gray-900 dark:text-gray-100 hover:bg-purple-50 dark:hover:bg-gray-800 flex items-center justify-between">
                                            <span class="font-semibold text-gray-900 dark:text-white" x-text="'.' + option.tld"></span>
                                            <span class="text-xs text-gray-600 dark:text-gray-300" x-text="option.popularity ? ('#' + option.popularity) : ''"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" :disabled="loading || (!prefixWord.trim() && !suffixWord.trim()) || tlds.length === 0"
                        class="w-full py-3.5 px-6 rounded-xl bg-purple-600 hover:bg-purple-700 disabled:bg-gray-300 dark:disabled:bg-gray-700 disabled:cursor-not-allowed text-white font-semibold text-base transition flex items-center justify-center gap-2">
                        <template x-if="loading">
                            <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </template>
                        <span x-text="loading ? statusText : 'Generate & Check Top 10 Domains'"></span>
                    </button>
                    
                    <div class="mt-3 text-center">
                        <p class="text-xs text-gray-500 dark:text-gray-400 flex items-center justify-center gap-1.5">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                            </svg>
                            <span>Your searches are never logged or tracked</span>
                        </p>
                    </div>
                </form>

                <!-- AI Generator Mode -->
                <form x-show="mode === 'ai'" @submit.prevent="searchAI()">
                    <div class="mb-5">
                        <div class="flex items-baseline justify-between mb-2">
                            <label for="ai-prompt" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Describe Your Domain Idea</label>
                            <span class="text-xs tabular-nums"
                                  :class="aiPrompt.length > 180 ? (aiPrompt.length >= 200 ? 'text-red-500 font-semibold' : 'text-amber-500') : 'text-gray-400 dark:text-gray-500'"
                                  x-text="aiPrompt.length + ' / 200'"></span>
                        </div>
                        <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                                    </svg>
                                </div>
                                <textarea
                                    id="ai-prompt"
                                    x-model="aiPrompt"
                                    rows="2"
                                    maxlength="200"
                                    placeholder="e.g., I need a domain for a modern fitness tracking app that focuses on mindfulness and wellness..."
                                    class="w-full pl-12 pr-4 py-3.5 rounded-xl border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-base resize-none"
                                ></textarea>
                            </div>
                            <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">Describe your domain idea. You can specify character length as well, the prompt is very flexible.</p>
                        </div>

                        <!-- Prompt Modifier -->
                        <div class="mb-5">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Creative Modifier
                                <span class="ml-1.5 text-xs font-normal text-gray-400 dark:text-gray-500">(optional twist on your idea)</span>
                            </label>
                            <div class="grid grid-cols-3 gap-2">
                                <button type="button"
                                    @click="promptModifier = ''"
                                    class="px-3 py-2.5 rounded-xl border text-sm font-medium transition flex flex-col items-center gap-1"
                                    :class="promptModifier === ''
                                        ? 'bg-indigo-100 dark:bg-indigo-900/30 border-indigo-300 dark:border-indigo-600 text-indigo-700 dark:text-indigo-300'
                                        : 'bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300'">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                    None
                                </button>
                                <button type="button"
                                    @click="promptModifier = 'phonetic'"
                                    class="px-3 py-2.5 rounded-xl border text-sm font-medium transition flex flex-col items-center gap-1"
                                    :class="promptModifier === 'phonetic'
                                        ? 'bg-indigo-100 dark:bg-indigo-900/30 border-indigo-300 dark:border-indigo-600 text-indigo-700 dark:text-indigo-300'
                                        : 'bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300'">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path></svg>
                                    Phonetic Twist
                                </button>
                                <button type="button"
                                    @click="promptModifier = 'numbers'"
                                    class="px-3 py-2.5 rounded-xl border text-sm font-medium transition flex flex-col items-center gap-1"
                                    :class="promptModifier === 'numbers'
                                        ? 'bg-indigo-100 dark:bg-indigo-900/30 border-indigo-300 dark:border-indigo-600 text-indigo-700 dark:text-indigo-300'
                                        : 'bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300'">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                                    Number Blend
                                </button>
                            </div>
                            <!-- Modifier hint -->
                            <p x-show="promptModifier === 'phonetic'" x-cloak class="mt-2 text-xs text-indigo-500 dark:text-indigo-400">
                                Suggests names using homophones and soundalike spellings — e.g. &ldquo;Phyre&rdquo; instead of &ldquo;Fire&rdquo;, or &ldquo;Kore&rdquo; instead of &ldquo;Core&rdquo;.
                            </p>
                            <p x-show="promptModifier === 'numbers'" x-cloak class="mt-2 text-xs text-indigo-500 dark:text-indigo-400">
                                Blends numbers into names in a clever, readable way &mdash; e.g. &ldquo;Studio360&rdquo;, &ldquo;4geLabs&rdquo;, &ldquo;Cloud9ly&rdquo;, or &ldquo;Nex7a&rdquo;.
                            </p>
                        </div>
                        <div class="mb-5">
                            <a href="/logo-generator"
                                class="group flex items-center gap-3 w-full p-3.5 rounded-xl border border-dashed border-indigo-300 dark:border-indigo-700 bg-indigo-50/50 dark:bg-indigo-900/10 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition">
                                <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                    </svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">Need a sleek logo for your domain?</p>
                                    <p class="text-xs text-indigo-600 dark:text-indigo-400 font-medium group-hover:text-indigo-700 dark:group-hover:text-indigo-300 transition">Try Logo Generator →</p>
                                </div>
                            </a>
                        </div>

                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Check Availability For</label>
                            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-3"
                                 @click.outside="closeTldTypeaheadFor('ai')">
                                <div class="flex flex-wrap gap-2 mb-2" x-show="tlds.length > 0">
                                    <template x-for="tld in tlds" :key="'ai-' + tld">
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold"
                                              :class="tldBadgeClass('.' + tld)">
                                            <span x-text="'.' + tld"></span>
                                            <button type="button" class="leading-none opacity-80 hover:opacity-100"
                                                    @click.prevent="removeTld(tld)">x</button>
                                        </span>
                                    </template>
                                </div>
                                <div class="relative">
                                    <input
                                        type="text"
                                        x-model="tldQuery"
                                        @focus="openTldTypeahead()"
                                        @input="updateTldSuggestions()"
                                        @keydown.enter.prevent="selectFirstTldSuggestion()"
                                        @keydown.escape.prevent="closeTldTypeahead()"
                                        placeholder="Type to add extensions (e.g. .io, .co, .xyz)"
                                        class="w-full px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
                                    />
                                    <div x-show="tldOpen && tldSuggestions.length > 0" x-cloak
                                         class="absolute z-[9999] mt-1 w-full max-h-56 overflow-auto rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-lg">
                                        <template x-for="option in tldSuggestions" :key="'ai-opt-' + option.tld">
                                            <button type="button"
                                                    @click.prevent="addTld(option.tld)"
                                                    class="w-full px-3 py-2 text-left text-sm text-gray-900 dark:text-gray-100 hover:bg-indigo-50 dark:hover:bg-gray-800 flex items-center justify-between">
                                                <span class="font-semibold text-gray-900 dark:text-white" x-text="'.' + option.tld"></span>
                                                <span class="text-xs text-gray-600 dark:text-gray-300" x-text="option.popularity ? ('#' + option.popularity) : ''"></span>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" :disabled="loading || !aiPrompt.trim() || tlds.length === 0"
                            class="w-full py-3.5 px-6 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 disabled:from-gray-300 disabled:to-gray-300 dark:disabled:from-gray-700 dark:disabled:to-gray-700 disabled:cursor-not-allowed text-white font-semibold text-base transition flex items-center justify-center gap-2">
                            <template x-if="loading">
                                <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </template>
                            <svg x-show="!loading" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                            <span x-text="loading ? 'Generating...' : 'Generate with AI'"></span>
                        </button>
                        
                        <div class="mt-3 text-center">
                            <p class="text-xs text-gray-500 dark:text-gray-400 flex items-center justify-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                @if($isDomainSearchLoggedIn)
                                    <span>AI usage tracked for billing. Domain searches remain private.</span>
                                @else
                                    <span>Unlimited domain search with account, otherwise <span x-text="remainingAiRequests"></span> free AI Generator requests remaining today. <a href="/admin/login" class="text-indigo-600 hover:underline">Login for unlimited requests</a></span>
                                @endif
                            </p>
                        </div>
                </form>
            </div>

            <!-- Error Message -->
            <template x-if="error">
                <div class="mb-6 p-4 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 text-sm">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span x-text="error"></span>
                    </div>
                </div>
            </template>

            <!-- Progress Bar (for generate mode) -->
            <template x-if="loading && mode === 'generate'">
                <div class="mb-6">
                    <div class="flex items-center justify-between text-sm text-gray-500 dark:text-gray-400 mb-2">
                        <span x-text="statusText"></span>
                        <span x-text="progress + '%'"></span>
                    </div>
                    <div class="w-full bg-gray-200 dark:bg-gray-800 rounded-full h-2 overflow-hidden">
                        <div class="progress-bar bg-purple-500 h-2 rounded-full" :style="'width: ' + progress + '%'"></div>
                    </div>
                </div>
            </template>

            <!-- Filter Bar (when results exist) -->
            <template x-if="results.length > 0">
                <div class="mb-4">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                            <span x-text="mode === 'direct' ? 'Check Domains Results' : (mode === 'generate' ? 'Generate Ideas Results' : 'AI Generator Results')"></span>
                        </h2>
                        <div class="flex items-center gap-3">
                            <div class="flex items-center gap-3 text-sm">
                                <span class="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                    <span x-text="results.filter(r => r.available && !r.premium).length"></span> available
                                </span>
                                <span x-show="results.filter(r => r.premium && r.available).length > 0" class="flex items-center gap-1.5 text-amber-600 dark:text-amber-400">
                                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                                    <span x-text="results.filter(r => r.premium && r.available).length"></span> premium
                                </span>
                                <span class="flex items-center gap-1.5 text-gray-400 dark:text-gray-500">
                                    <span class="w-2 h-2 rounded-full bg-gray-400 dark:bg-gray-600"></span>
                                    <span x-text="results.filter(r => r.taken).length"></span> taken
                                </span>
                            </div>
                            <button
                                x-show="canRefreshSavedDomains"
                                @click="refreshSavedDomains()"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-200 transition hover:border-gray-300 dark:hover:border-gray-600 disabled:opacity-60"
                                :disabled="refreshButtonDisabled"
                            >
                                <svg class="h-3.5 w-3.5" :class="{ 'animate-spin': refreshBusy }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582M20 20v-5h-.581M5.49 9A9 9 0 0119 7.24M18.51 15A9 9 0 015 16.76"></path>
                                </svg>
                                <span x-text="refreshButtonLabel"></span>
                            </button>
                            <!-- View Toggle -->
                            <div class="flex items-center rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                                <button @click="viewMode = 'list'" type="button"
                                    class="p-1.5 transition"
                                    :class="viewMode === 'list' ? 'bg-gray-900 dark:bg-white text-white dark:text-gray-900' : 'bg-white dark:bg-gray-800 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300'">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                                    </svg>
                                </button>
                                <button @click="viewMode = 'grid'" type="button"
                                    class="p-1.5 transition"
                                    :class="viewMode === 'grid' ? 'bg-gray-900 dark:bg-white text-white dark:text-gray-900' : 'bg-white dark:bg-gray-800 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300'">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Buttons -->
                    <div class="flex flex-wrap gap-2 mb-4">
                        <button @click="filter = 'all'"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg border transition"
                            :class="filter === 'all'
                                ? 'bg-gray-900 dark:bg-white text-white dark:text-gray-900 border-transparent'
                                : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300'">
                            All <span class="ml-1 opacity-60" x-text="new Set(results.map(r => (r.domain || '').split('.')[0].toLowerCase()).filter(Boolean)).size"></span>
                        </button>
                        <button @click="filter = 'available'"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg border transition"
                            :class="filter === 'available'
                                ? 'bg-emerald-600 text-white border-transparent'
                                : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300'">
                            Available <span class="ml-1 opacity-60" x-text="results.filter(r => r.available && !r.premium).length"></span>
                        </button>
                        <button @click="filter = 'premium'"
                            x-show="results.filter(r => r.premium && r.available).length > 0"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg border transition"
                            :class="filter === 'premium'
                                ? 'bg-amber-500 text-white border-transparent'
                                : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300'">
                            Premium <span class="ml-1 opacity-60" x-text="results.filter(r => r.premium && r.available).length"></span>
                        </button>
                        <button @click="filter = 'taken'"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg border transition"
                            :class="filter === 'taken'
                                ? 'bg-gray-500 text-white border-transparent'
                                : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300'">
                            Taken <span class="ml-1 opacity-60" x-text="results.filter(r => r.taken).length"></span>
                        </button>
                    </div>

                    <!-- TLD Extension Filter -->
                    <div x-show="results.length > 0" class="flex flex-wrap gap-2 mb-2">
                        <template x-for="ext in [...new Set(results.filter(r => r.available).map(r => r.tld).filter(Boolean))].sort()" :key="ext">
                            <button
                                @click="filterExt === ext ? filterExt = '' : filterExt = ext"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg border transition"
                                :class="filterExt === ext
                                    ? 'bg-gray-900 dark:bg-white text-white dark:text-gray-900 border-transparent'
                                    : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300'"
                                x-text="ext"></button>
                        </template>
                    </div>
                </div>
            </template>

            <!-- Results: grouped by base name -->
            <template x-if="groupedResults.length > 0">
                <div :class="viewMode === 'grid' ? 'grid grid-cols-1 sm:grid-cols-2 gap-3' : 'space-y-3'">
                    <template x-for="group in groupedResults" :key="group.name">
                        <div
                            class="result-enter rounded-xl border p-4 transition-all bg-white dark:bg-gray-900 hover:shadow-md"
                            :class="group.hasPremiumAvailable
                                ? 'border-amber-200 dark:border-amber-800/50 hover:shadow-amber-100 dark:hover:shadow-amber-900/20'
                                : group.hasAvailable
                                    ? 'border-emerald-200 dark:border-emerald-800/50 hover:shadow-emerald-100 dark:hover:shadow-emerald-900/20'
                                    : 'border-gray-200 dark:border-gray-800 opacity-80'"
                        >
                            <!-- Name row -->
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-xl font-bold text-gray-900 dark:text-white tracking-tight" x-text="group.displayName"></h3>
                                <div class="flex items-center gap-1.5 flex-shrink-0">
                                    <!-- Exclude / trash icon -->
                                    <div class="relative group/tip">
                                        <button type="button"
                                            @click.stop="toggleExclude(group.name)"
                                            class="flex items-center justify-center w-8 h-8 rounded-lg border transition"
                                            :class="isExcluded(group.name)
                                                ? 'bg-gray-900 dark:bg-white border-transparent text-white dark:text-gray-900'
                                                : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-300 dark:text-gray-600 hover:text-red-400 hover:border-red-300'">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                        <div class="pointer-events-none absolute bottom-full right-0 mb-2 w-max max-w-[180px] rounded-lg bg-gray-900 dark:bg-gray-700 px-2.5 py-1.5 text-xs text-white opacity-0 group-hover/tip:opacity-100 transition-opacity z-10">
                                            Remove this domain from searches
                                            <div class="absolute top-full right-3 border-4 border-transparent border-t-gray-900 dark:border-t-gray-700"></div>
                                        </div>
                                    </div>
                                    <!-- One-click logo design (show if any TLD is available) -->
                                    <template x-if="group.bestAvailableDomain">
                                        <a :href="'/logo-generator?domain=' + encodeURIComponent(group.bestAvailableDomain || '')"
                                           class="inline-flex items-center h-8 px-2.5 rounded-lg text-xs font-semibold border border-indigo-300 dark:border-indigo-700 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100 dark:hover:bg-indigo-900/30 transition"
                                           title="Design a logo for this domain">
                                            Design
                                        </a>
                                    </template>
                                </div>
                            </div>

                            <!-- TLD badge row: available → Namecheap link; taken → RDAP lookup -->
                            <div class="flex flex-wrap gap-2" :class="rdapOpen && group.tlds.some(r => r.domain === rdapOpen) ? 'mb-2' : 'mb-3'">
                                <template x-for="r in group.tlds" :key="r.domain">
                                    <div class="inline-flex rounded-lg border overflow-hidden text-xs font-semibold transition hover:shadow-sm"
                                         :class="r.premium && r.available
                                             ? 'bg-amber-100 dark:bg-amber-900/30 border-amber-300 dark:border-amber-700 text-amber-800 dark:text-amber-300'
                                             : r.available
                                                 ? 'bg-emerald-100 dark:bg-emerald-900/30 border-emerald-300 dark:border-emerald-700 text-emerald-800 dark:text-emerald-300'
                                                 : r.error
                                                     ? 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800 text-amber-600 dark:text-amber-400'
                                                     : rdapOpen === r.domain
                                                         ? 'bg-blue-100 dark:bg-blue-900/30 border-blue-400 dark:border-blue-600 text-blue-700 dark:text-blue-300'
                                                         : 'bg-gray-100 dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-400 dark:text-gray-500'">
                                        <!-- Clickable TLD portion -->
                                        <a :href="r.available ? 'https://www.namecheap.com/domains/registration/results/?domain=' + r.domain : r.error ? 'https://www.godaddy.com/domainsearch/find?checkAvail=1&domainToCheck=' + r.domain : '#'"
                                           :target="(r.available || r.error) ? '_blank' : '_self'"
                                           :title="r.available ? 'Register on Namecheap' : r.error ? 'Check on GoDaddy (TLD unsupported by Namecheap)' : 'Click for registration info'"
                                           @click="if (!r.available && !r.error) { $event.preventDefault(); fetchRdap(r.domain); }"
                                           class="flex items-center gap-1.5 px-2.5 py-1 hover:opacity-80 transition-opacity">
                                            <span class="w-1.5 h-1.5 rounded-full flex-shrink-0"
                                                  :class="r.premium && r.available ? 'bg-amber-500' : r.available ? 'bg-emerald-500' : r.error ? 'bg-amber-400' : rdapOpen === r.domain ? 'bg-blue-500' : 'bg-gray-400 dark:bg-gray-600'"></span>
                                            <span x-text="r.tld"></span>
                                            <span x-show="r.premium && r.available && r.premium_price" class="opacity-70" x-text="r.premium_price ? '$' + Number(r.premium_price).toLocaleString() : ''"></span>
                                            <span x-show="r.error" class="opacity-60 font-normal">· GD</span>
                                            <template x-if="!r.available && !r.error && rdapLoading === r.domain">
                                                <svg class="w-3 h-3 animate-spin opacity-60" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                                            </template>
                                            <template x-if="!r.available && !r.error && rdapLoading !== r.domain">
                                                <svg class="w-3 h-3 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                            </template>
                                        </a>
                                        <!-- Divider + heart (logged-in users only) -->
                                        <template x-if="isLoggedIn">
                                            <div class="flex items-center">
                                                <span class="w-px self-stretch bg-current opacity-20"></span>
                                                <button type="button"
                                                    @click.prevent.stop="toggleSaved(r.domain, r.available, r.premium, r.premium_price)"
                                                    class="flex items-center self-stretch px-1.5 transition-opacity"
                                                    :class="isSaved(r.domain) ? 'text-rose-500 bg-rose-50 dark:bg-rose-900/30 opacity-100' : 'opacity-40 hover:opacity-100'"
                                                    :title="isSaved(r.domain) ? 'Remove from saved' : 'Save domain'">
                                                    <svg class="w-3 h-3" :fill="isSaved(r.domain) ? 'currentColor' : 'none'" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                                                    </svg>
                                                </button>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>

                            <!-- RDAP info panel — shown when a taken domain in this group is selected -->
                            <template x-if="rdapOpen && group.tlds.some(r => r.domain === rdapOpen)">
                                <div class="mb-3 rounded-xl border border-blue-200 dark:border-blue-800/50 bg-blue-50/40 dark:bg-blue-900/10 p-3">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="flex items-center gap-1.5 text-xs font-semibold text-blue-600 dark:text-blue-400 uppercase tracking-wide">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path></svg>
                                            RDAP
                                            <span class="normal-case font-mono font-medium" x-text="rdapOpen"></span>
                                        </span>
                                        <button type="button" @click="rdapOpen = null" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 flex items-center justify-center w-5 h-5 rounded">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"></path></svg>
                                        </button>
                                    </div>
                                    <!-- Loading -->
                                    <div x-show="rdapLoading === rdapOpen" class="flex items-center gap-2 text-xs text-gray-400 dark:text-gray-500">
                                        <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                                        Fetching registration data…
                                    </div>
                                    <!-- Error -->
                                    <div x-show="rdap[rdapOpen] && rdap[rdapOpen].error && rdapLoading !== rdapOpen"
                                         class="text-xs text-red-400" x-text="(rdap[rdapOpen] || {}).error"></div>
                                    <!-- Data -->
                                    <div x-show="rdap[rdapOpen] && !rdap[rdapOpen].error && rdapLoading !== rdapOpen"
                                         class="space-y-1.5">
                                        <div x-show="(rdap[rdapOpen] || {}).status" class="grid gap-x-3 text-xs" style="grid-template-columns:5rem 1fr">
                                            <span class="text-gray-400 dark:text-gray-500">Status</span>
                                            <span class="text-gray-700 dark:text-gray-300 break-words" x-text="(rdap[rdapOpen] || {}).status"></span>
                                        </div>
                                        <div x-show="(rdap[rdapOpen] || {}).created" class="grid gap-x-3 text-xs" style="grid-template-columns:5rem 1fr">
                                            <span class="text-gray-400 dark:text-gray-500">Created</span>
                                            <span class="text-gray-700 dark:text-gray-300 font-mono" x-text="(rdap[rdapOpen] || {}).created"></span>
                                        </div>
                                        <div x-show="(rdap[rdapOpen] || {}).expires" class="grid gap-x-3 text-xs" style="grid-template-columns:5rem 1fr">
                                            <span class="text-gray-400 dark:text-gray-500">Expires</span>
                                            <span class="text-gray-700 dark:text-gray-300 font-mono" x-text="(rdap[rdapOpen] || {}).expires"></span>
                                        </div>
                                        <div x-show="(rdap[rdapOpen] || {}).changed" class="grid gap-x-3 text-xs" style="grid-template-columns:5rem 1fr">
                                            <span class="text-gray-400 dark:text-gray-500">Changed</span>
                                            <span class="text-gray-700 dark:text-gray-300 font-mono" x-text="(rdap[rdapOpen] || {}).changed"></span>
                                        </div>
                                        <div x-show="(rdap[rdapOpen] || {}).registrar" class="grid gap-x-3 text-xs" style="grid-template-columns:5rem 1fr">
                                            <span class="text-gray-400 dark:text-gray-500">Registrar</span>
                                            <span class="text-gray-700 dark:text-gray-300" x-text="(rdap[rdapOpen] || {}).registrar + ((rdap[rdapOpen] || {}).registrarId ? ' (#' + rdap[rdapOpen].registrarId + ')' : '')"></span>
                                        </div>
                                        <div x-show="(rdap[rdapOpen] || {}).nameservers" class="grid gap-x-3 text-xs" style="grid-template-columns:5rem 1fr">
                                            <span class="text-gray-400 dark:text-gray-500">Nameservers</span>
                                            <span class="text-gray-700 dark:text-gray-300 font-mono break-all" x-text="(rdap[rdapOpen] || {}).nameservers"></span>
                                        </div>
                                    </div>
                                </div>
                            </template>

                        </div>
                    </template>
                </div>
            </template>

            <!-- Empty State -->
            <template x-if="!loading && groupedResults.length === 0 && searched">
                <div class="text-center py-12">
                    <svg class="mx-auto h-12 w-12 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <p class="mt-3 text-gray-400 dark:text-gray-500">No results found. Try a different name.</p>
                </div>
            </template>

        </div>
    </main>

    <script>
        const domainSearchTldOptions = @js($tldOptions ?? []);
        const domainSearchDefaultTlds = @js($defaultTlds ?? ['com', 'ai', 'net', 'org']);

        function domainSearch() {
            return {
                mode: 'direct',
                resultsByMode: {
                    direct: [],
                    generate: [],
                    ai: [],
                },
                searchedByMode: {
                    direct: false,
                    generate: false,
                    ai: false,
                },
                fileNames: [],
                fileNameLoaded: '',
                fileUploadError: '',
                fileDragOver: false,
                query: '',
                prefixWord: '',
                suffixWord: '',
                selectedCategory: 'tech',
                wordMinLength: 4,
                wordMaxLength: 10,
                isLoggedIn: {{ (auth()->check() || auth('admin')->check()) ? 'true' : 'false' }},
                canRefreshSavedDomains: {{ (!empty($canRefreshSavedDomains)) ? 'true' : 'false' }},
                savedDomains: new Set(@json($savedDomains ?? [])),
                refreshCooldownUntil: null,
                refreshNowMs: Date.now(),
                refreshBusy: false,
                categoryOptions: [
                    { value: 'tech',    label: 'Tech' },
                    { value: 'fantasy', label: 'Fantasy' },
                    { value: 'scifi',   label: 'Sci-Fi' },
                    { value: 'horror',  label: 'Horror' },
                    { value: 'romance', label: 'Romance' },
                    { value: 'mtg',     label: 'MTG' },
                ],
                aiPrompt: '',
                promptModifier: '',
                aiUsage: null,
                aiCostText: '$0.00',
                remainingAiRequests: {{ $remainingAiRequests ?? 25 }},
                remainingFileUploads: {{ $remainingFileUploads ?? 5 }},
                rdap: {},
                rdapLoading: null,
                rdapOpen: null,
                excluded: [],
                tlds: [],
                tldOptions: Array.isArray(domainSearchTldOptions) ? domainSearchTldOptions : [],
                tldQuery: '',
                tldOpen: false,
                tldSuggestions: [],
                loading: false,
                results: [],
                error: null,
                searched: false,
                filter: 'all',
                filterExt: '',
                progress: 0,
                statusText: 'Starting...',
                viewMode: 'grid',
                pollInterval: null,
                init() {
                    const normalize = (raw) => String(raw || '').toLowerCase().replace(/^\./, '').replace(/[^a-z0-9-]/g, '');

                    if (!this.tldOptions.length) {
                        this.tldOptions = domainSearchDefaultTlds.map((tld) => ({
                            tld: normalize(tld),
                            popularity: null,
                            manager: null,
                        }));
                    }

                    const available = new Set(this.tldOptions.map((option) => normalize(option.tld)));
                    const selected = domainSearchDefaultTlds
                        .map((tld) => normalize(tld))
                        .filter((tld) => available.has(tld));

                    this.tlds = selected.length ? [...new Set(selected)] : [...new Set(domainSearchDefaultTlds.map((tld) => normalize(tld)))];
                    this.updateTldSuggestions();
                    setInterval(() => { this.refreshNowMs = Date.now(); }, 1000);
                    if (this.canRefreshSavedDomains) {
                        this.fetchSavedDomainRefreshStatus();
                    }
                },

                setMode(nextMode) {
                    if (nextMode === this.mode) return;

                    this.resultsByMode[this.mode] = Array.isArray(this.results) ? [...this.results] : [];
                    this.searchedByMode[this.mode] = !!this.searched;

                    this.mode = nextMode;

                    this.results = Array.isArray(this.resultsByMode[nextMode]) ? [...this.resultsByMode[nextMode]] : [];
                    this.searched = !!this.searchedByMode[nextMode];
                    this.error = null;
                    this.filter = 'all';
                    this.filterExt = '';
                },

                normalizeTld(raw) {
                    return String(raw || '').toLowerCase().replace(/^\./, '').replace(/[^a-z0-9-]/g, '');
                },

                openTldTypeahead() {
                    this.tldOpen = true;
                    this.updateTldSuggestions();
                },

                closeTldTypeahead() {
                    this.tldOpen = false;
                    this.tldQuery = '';
                    this.updateTldSuggestions();
                },

                closeTldTypeaheadFor(activeMode) {
                    if (this.mode !== activeMode) return;
                    this.closeTldTypeahead();
                },

                updateTldSuggestions() {
                    const query = this.normalizeTld(this.tldQuery);
                    const selected = new Set(this.tlds);

                    let options = this.tldOptions
                        .map((option) => ({
                            tld: this.normalizeTld(option.tld),
                            popularity: option.popularity,
                            manager: option.manager,
                        }))
                        .filter((option) => option.tld && !selected.has(option.tld));

                    if (query) {
                        options = options.filter((option) => option.tld.includes(query));
                    }

                    options.sort((a, b) => {
                        const aStarts = query && a.tld.startsWith(query) ? 0 : 1;
                        const bStarts = query && b.tld.startsWith(query) ? 0 : 1;
                        if (aStarts !== bStarts) return aStarts - bStarts;

                        const aPop = a.popularity === null || a.popularity === undefined ? Number.MAX_SAFE_INTEGER : Number(a.popularity);
                        const bPop = b.popularity === null || b.popularity === undefined ? Number.MAX_SAFE_INTEGER : Number(b.popularity);
                        if (aPop !== bPop) return aPop - bPop;

                        return a.tld.localeCompare(b.tld);
                    });

                    this.tldSuggestions = options.slice(0, 20);
                },

                addTld(rawTld) {
                    const tld = this.normalizeTld(rawTld);
                    if (!tld || this.tlds.includes(tld)) return;

                    const exists = this.tldOptions.some((option) => this.normalizeTld(option.tld) === tld);
                    if (!exists) return;

                    this.tlds = [...this.tlds, tld];
                    this.tldQuery = '';
                    this.updateTldSuggestions();
                    this.tldOpen = false;
                },

                removeTld(rawTld) {
                    const tld = this.normalizeTld(rawTld);
                    this.tlds = this.tlds.filter((item) => item !== tld);
                    this.updateTldSuggestions();
                },

                selectFirstTldSuggestion() {
                    if (this.tldSuggestions.length === 0) return;
                    this.addTld(this.tldSuggestions[0].tld);
                },

                tldBadgeClass(rawTld) {
                    const tld = this.normalizeTld(rawTld);
                    if (tld === 'com') return 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300';
                    if (tld === 'ai') return 'bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300';
                    if (tld === 'net') return 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300';
                    if (tld === 'org') return 'bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-300';
                    return 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300';
                },

                sortResults(results) {
                    return [...results].sort((a, b) => {
                        // Sort order: available (non-premium) > premium (available) > taken
                        const rank = (r) => r.available && !r.premium ? 0 : r.premium && r.available ? 1 : 2;
                        const ra = rank(a), rb = rank(b);
                        if (ra !== rb) return ra - rb;
                        return String(a.domain || '').localeCompare(String(b.domain || ''));
                    });
                },

                computeAiCost(usage) {
                    if (!usage) return '$0.00';
                    const inputTokens = usage.promptTokenCount || 0;
                    const outputTokens = usage.candidatesTokenCount || 0;
                    const inputCost = (inputTokens / 1_000_000) * 0.10;
                    const outputCost = (outputTokens / 1_000_000) * 0.40;
                    const total = inputCost + outputCost;
                    if (total === 0) return '$0.00';
                    return `$${total.toFixed(6)}`;
                },

                normalizeExcludeName(domain) {
                    return (domain || '').split('.')[0].toLowerCase();
                },

                isExcluded(domain) {
                    const name = this.normalizeExcludeName(domain);
                    return this.excluded.includes(name);
                },

                toggleExclude(domain) {
                    const name = this.normalizeExcludeName(domain);
                    if (!name) return;
                    if (this.excluded.includes(name)) {
                        this.excluded = this.excluded.filter(item => item !== name);
                    } else {
                        this.excluded.push(name);
                    }
                },

                isSaved(domain) {
                    return this.savedDomains.has((domain || '').toLowerCase());
                },

                async toggleSaved(domain, isAvailable = null, isPremium = null, premiumPrice = null) {
                    if (!this.isLoggedIn) return;
                    const d = (domain || '').toLowerCase();
                    try {
                        const response = await fetch('{{ route('domainSearch.toggleSaved') }}', {
                            method: 'POST',
                            headers: this.headers(),
                            body: JSON.stringify({
                                domain: d,
                                is_available: isAvailable,
                                is_premium: isPremium ?? false,
                                premium_price: premiumPrice ?? null,
                            }),
                        });
                        if (!response.ok) return;
                        const data = await response.json();
                        if (data.saved) {
                            this.savedDomains.add(d);
                        } else {
                            this.savedDomains.delete(d);
                        }
                        this.savedDomains = new Set(this.savedDomains);
                    } catch (e) {
                        // silent fail
                    }
                },

                get resultExtensions() {
                    return [...new Set(this.results.map(r => r.domain ? r.domain.split('.').slice(1).join('.') : null).filter(Boolean))];
                },

                get groupedResults() {
                    const map = {};
                    for (const r of this.results) {
                        const base = (r.domain || '').split('.')[0].toLowerCase();
                        if (!base) continue;
                        if (!map[base]) map[base] = { name: base, tlds: [] };
                        map[base].tlds.push(r);
                    }

                    let groups = Object.values(map).map(g => {
                        const hasPremiumAvailable = g.tlds.some(r => r.premium && r.available);
                        const hasAvailable        = g.tlds.some(r => r.available && !r.premium);
                        const bestTld = hasPremiumAvailable
                            ? null // prefer regular available for Register link
                            : g.tlds.find(r => r.available && !r.premium);
                        const premiumTld = g.tlds.find(r => r.premium && r.available);
                        const registerTarget = bestTld || premiumTld || null;
                        return {
                            ...g,
                            displayName: g.name.charAt(0).toUpperCase() + g.name.slice(1),
                            hasPremiumAvailable,
                            hasAvailable,
                            bestAvailableDomain: registerTarget ? registerTarget.domain : null,
                        };
                    });

                    if (this.filter === 'available') groups = groups.filter(g => g.hasAvailable);
                    if (this.filter === 'premium')   groups = groups.filter(g => g.hasPremiumAvailable);
                    if (this.filter === 'taken')     groups = groups.filter(g => !g.hasAvailable && !g.hasPremiumAvailable);

                    if (this.filterExt) {
                        const ext = this.filterExt;
                        groups = groups.filter(g => g.tlds.some(r => r.available && r.tld === ext));
                    }

                    return groups;
                },

                get filteredResults() {
                    let res = this.results;
                    if (this.filter === 'available') return res.filter(r => r.available && !r.premium);
                    if (this.filter === 'premium') return res.filter(r => r.premium && r.available);
                    if (this.filter === 'taken') return res.filter(r => r.taken);
                    return res;
                },

                headers() {
                    return {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        'Accept': 'application/json',
                    };
                },

                get refreshCooldownSeconds() {
                    if (!this.refreshCooldownUntil) return 0;
                    const ms = new Date(this.refreshCooldownUntil).getTime() - this.refreshNowMs;
                    return Math.max(0, Math.ceil(ms / 1000));
                },

                get refreshButtonDisabled() {
                    return this.refreshBusy || this.refreshCooldownSeconds > 0;
                },

                get refreshButtonLabel() {
                    if (this.refreshBusy) return 'Refreshing...';
                    if (this.refreshCooldownSeconds > 0) {
                        return `Refresh in ${this.formatCooldown(this.refreshCooldownSeconds)}`;
                    }
                    return 'Refresh Domains';
                },

                formatCooldown(totalSeconds) {
                    const seconds = Math.max(0, Number(totalSeconds || 0));
                    const hours = Math.floor(seconds / 3600);
                    const minutes = Math.floor((seconds % 3600) / 60);
                    const secs = seconds % 60;
                    if (hours > 0) return `${hours}h ${minutes}m`;
                    if (minutes > 0) return `${minutes}m ${secs}s`;
                    return `${secs}s`;
                },

                async fetchSavedDomainRefreshStatus() {
                    try {
                        const res = await fetch('{{ route('domainSearch.savedDomainsRefreshStatus') }}', {
                            method: 'GET',
                            headers: this.headers(),
                        });
                        const data = await res.json();
                        if (res.ok) {
                            this.refreshCooldownUntil = data.next_available_at || null;
                        }
                    } catch (e) {
                        // Silent fail for status check.
                    }
                },

                async refreshSavedDomains() {
                    if (!this.canRefreshSavedDomains || this.refreshButtonDisabled) return;
                    this.refreshBusy = true;
                    try {
                        const res = await fetch('{{ route('domainSearch.refreshSavedDomains') }}', {
                            method: 'POST',
                            headers: this.headers(),
                        });
                        const data = await res.json();

                        if (!res.ok) {
                            if (data.cooldown?.next_available_at) {
                                this.refreshCooldownUntil = data.cooldown.next_available_at;
                            }
                            this.error = data.error || 'Failed to refresh saved domains.';
                            return;
                        }

                        this.refreshCooldownUntil = data.cooldown?.next_available_at || null;
                        this.error = null;
                    } catch (e) {
                        this.error = 'Failed to refresh saved domains.';
                    } finally {
                        this.refreshBusy = false;
                    }
                },

                async searchDirect() {
                    // If names were loaded from a file, use the array path via check-start
                    if (this.fileNames.length > 0) {
                        if (this.tlds.length === 0) return;
                        this.loading = true;
                        this.error = null;
                        this.results = [];
                        this.searched = true;
                        this.filter = 'all';
                        this.filterExt = '';
                        this.statusText = `Checking ${this.fileNames.length} domains from file...`;
                        this.startAvailabilityPolling(this.fileNames);
                        return;
                    }

                    if (!this.query.trim() || this.tlds.length === 0) return;

                    this.loading = true;
                    this.error = null;
                    this.results = [];
                    this.searched = true;
                    this.filter = 'all';
                    this.filterExt = '';

                    try {
                        const response = await fetch('/domain-search/check', {
                            method: 'POST',
                            headers: this.headers(),
                            body: JSON.stringify({ names: this.query, tlds: this.tlds }),
                        });

                        const data = await response.json();

                        if (!response.ok) {
                            this.error = data.error || data.message || 'Something went wrong.';
                            return;
                        }

                        if (data.error) this.error = data.error;

                        this.results = this.sortResults(data.results || []);
                    } catch (e) {
                        this.error = 'Network error. Please try again.';
                    } finally {
                        this.loading = false;
                    }
                },

                async searchGenerate() {
                    const prefixVal = this.prefixWord.trim().replace(/[^a-zA-Z0-9-]/g, '').toLowerCase();
                    const suffixVal = this.suffixWord.trim().replace(/[^a-zA-Z0-9-]/g, '').toLowerCase();
                    if ((!prefixVal && !suffixVal) || this.tlds.length === 0) return;

                    this.loading = true;
                    this.error = null;
                    this.results = [];
                    this.searched = true;
                    this.filter = 'all';
                    this.filterExt = '';
                    this.progress = 0;
                    this.statusText = 'Generating names...';

                    try {
                        // Step 1: Generate names
                        this.progress = 10;
                        const genResponse = await fetch('/domain-search/generate', {
                            method: 'POST',
                            headers: this.headers(),
                            body: JSON.stringify({
                                prefix: prefixVal,
                                suffix: suffixVal,
                                category: this.selectedCategory,
                                min_length: this.wordMinLength,
                                max_length: this.wordMaxLength,
                            }),
                        });

                        const genData = await genResponse.json();

                        if (!genResponse.ok || genData.error) {
                            this.error = genData.error || genData.message || 'Failed to generate names.';
                            return;
                        }

                        const names = genData.names || [];
                        this.progress = 25;
                        this.statusText = `Checking ${names.length} domains...`;

                        if (names.length === 0) {
                            this.error = 'No names could be generated.';
                            return;
                        }

                        // Step 2: Check availability in batches for progress
                        const batchSize = 10;
                        const batches = [];
                        for (let i = 0; i < names.length; i += batchSize) {
                            batches.push(names.slice(i, i + batchSize));
                        }

                        let allResults = [];
                        for (let i = 0; i < batches.length; i++) {
                            const batch = batches[i];
                            const checkResponse = await fetch('/domain-search/check', {
                                method: 'POST',
                                headers: this.headers(),
                                body: JSON.stringify({
                                    names: batch.join(','),
                                    tlds: this.tlds,
                                }),
                            });

                            const checkData = await checkResponse.json();

                            if (checkData.results) {
                                allResults = allResults.concat(checkData.results);
                            }

                            this.progress = Math.round(20 + (80 * (i + 1) / batches.length));
                            this.statusText = `Checked ${Math.min((i + 1) * batchSize, names.length)} of ${names.length}...`;

                            // Show results incrementally — available first
                            this.results = this.sortResults(allResults);
                        }

                        this.statusText = 'Done!';

                    } catch (e) {
                        this.error = 'Network error. Please try again.';
                    } finally {
                        this.loading = false;
                    }
                },

                async handleFileUpload(file) {
                    this.fileUploadError = '';
                    if (!file) return;

                    const allowed = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'];
                    const ext = file.name.split('.').pop().toLowerCase();
                    if (!allowed.includes(file.type) && !['csv', 'txt'].includes(ext)) {
                        this.fileUploadError = 'Only .csv and .txt files are supported.';
                        return;
                    }

                    // Rate-limit check for guests
                    if (!this.isLoggedIn) {
                        try {
                            const res = await fetch('/domain-search/record-file-upload', {
                                method: 'POST',
                                headers: this.headers(),
                            });
                            const data = await res.json();
                            if (!data.allowed) {
                                this.fileUploadError = data.error || 'File upload limit reached. Log in for unlimited uploads.';
                                return;
                            }
                            if (data.remaining !== null && data.remaining !== undefined) {
                                this.remainingFileUploads = data.remaining;
                            }
                        } catch (err) {
                            // If the check itself fails, allow the upload (fail open)
                        }
                    }

                    const reader = new FileReader();
                    reader.onload = (e) => {
                        const names = this.parseFileNames(e.target.result);
                        if (names.length === 0) {
                            this.fileUploadError = 'No valid domain names found in the file.';
                            return;
                        }
                        this.fileNames = names;
                        this.fileNameLoaded = file.name;
                        this.query = ''; // clear manual input when file is loaded
                    };
                    reader.onerror = () => { this.fileUploadError = 'Failed to read the file.'; };
                    reader.readAsText(file);
                },

                parseFileNames(text) {
                    // Split on newlines first, then commas/semicolons/tabs within each line
                    const tokens = [];
                    for (const line of text.split(/\r?\n/)) {
                        const trimmed = line.trim();
                        if (!trimmed || trimmed.startsWith('#')) continue; // blank or comment
                        // Split line by comma, semicolon, tab, or space
                        for (const tok of trimmed.split(/[,;\t\s]+/)) {
                            tokens.push(tok.trim());
                        }
                    }

                    const seen = new Set();
                    const names = [];
                    for (const tok of tokens) {
                        if (!tok) continue;
                        // Strip surrounding quotes
                        const unquoted = tok.replace(/^["']|["']$/g, '');
                        // If it looks like a FQDN, strip the TLD(s) — take everything before first dot
                        const base = unquoted.split('.')[0];
                        // Sanitize: lowercase, keep only a-z0-9 and hyphens
                        const clean = base.toLowerCase().replace(/[^a-z0-9-]/g, '').replace(/^-+|-+$/g, '');
                        if (clean.length < 2 || seen.has(clean)) continue;
                        seen.add(clean);
                        names.push(clean);
                        if (names.length >= 100) break;
                    }
                    return names;
                },

                async fetchRdap(domain) {
                    // Toggle closed if already open
                    if (this.rdapOpen === domain) {
                        this.rdapOpen = null;
                        return;
                    }
                    this.rdapOpen = domain;
                    // Return cached data
                    if (this.rdap[domain]) return;

                    this.rdapLoading = domain;
                    try {
                        const res = await fetch(`https://rdap.org/domain/${encodeURIComponent(domain)}`, {
                            headers: { 'Accept': 'application/rdap+json, application/json' },
                        });
                        if (!res.ok) throw new Error('HTTP ' + res.status);
                        const d = await res.json();

                        const getDate = (action) => {
                            const ev = (d.events || []).find(e => e.eventAction === action);
                            return ev ? ev.eventDate.slice(0, 10) : null;
                        };

                        // Registrar entity
                        const regEntity = (d.entities || []).find(e => (e.roles || []).includes('registrar'));
                        const vcardFn = regEntity?.vcardArray?.[1]?.find?.(f => f[0] === 'fn');
                        const regName = vcardFn ? vcardFn[3] : null;
                        const regId = regEntity?.publicIds?.find?.(p => p.type === 'IANA Registrar ID')?.identifier ?? null;

                        this.rdap[domain] = {
                            status:      (d.status || []).join(', ') || null,
                            created:     getDate('registration'),
                            expires:     getDate('expiration'),
                            changed:     getDate('last changed'),
                            registrar:   regName,
                            registrarId: regId,
                            nameservers: (d.nameservers || []).map(n => (n.ldhName || '').toLowerCase()).filter(Boolean).join(', ') || null,
                        };
                    } catch (e) {
                        this.rdap[domain] = { error: 'Could not retrieve registration data for this domain.' };
                    } finally {
                        this.rdapLoading = null;
                    }
                },

                startAvailabilityPolling(domains) {
                    if (this.pollInterval) {
                        clearInterval(this.pollInterval);
                        this.pollInterval = null;
                    }

                    fetch('/domain-search/check-start', {
                        method: 'POST',
                        headers: this.headers(),
                        body: JSON.stringify({ names: domains, tlds: this.tlds }),
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.error && !data.results) {
                            this.error = data.error;
                            this.loading = false;
                            return;
                        }

                        // Namecheap fast-path: results returned instantly (no polling needed)
                        if (data.instant && data.done) {
                            if (data.results && data.results.length > 0) {
                                this.results.push(...data.results);
                                this.results = this.sortResults(this.results);
                            }
                            if (data.error) {
                                this.error = data.error;
                            }
                            this.statusText = 'Done!';
                            this.loading = false;
                            return;
                        }

                        // WHOIS fallback: poll for results in background
                        const jobId = data.job_id;
                        let offset = 0;

                        this.pollInterval = setInterval(async () => {
                            try {
                                const res = await fetch(`/domain-search/check-poll?job_id=${jobId}&offset=${offset}`);
                                const poll = await res.json();

                                if (!res.ok) {
                                    this.error = poll.error || 'Check failed.';
                                    clearInterval(this.pollInterval);
                                    this.pollInterval = null;
                                    this.loading = false;
                                    return;
                                }

                                if (poll.results && poll.results.length > 0) {
                                    this.results.push(...poll.results);
                                    this.results = this.sortResults(this.results);
                                    this.statusText = `Checked ${this.results.length} domains...`;
                                }

                                offset = poll.offset;

                                if (poll.done) {
                                    clearInterval(this.pollInterval);
                                    this.pollInterval = null;
                                    this.statusText = 'Done!';
                                    this.loading = false;
                                }
                            } catch (e) {
                                this.error = 'Polling error. Please try again.';
                                clearInterval(this.pollInterval);
                                this.pollInterval = null;
                                this.loading = false;
                            }
                        }, 1500);
                    })
                    .catch(e => {
                        this.error = 'Failed to start availability check.';
                        this.loading = false;
                    });
                },

                async pollAiGeneration(jobId) {
                    const startedAt = Date.now();
                    const timeoutMs = 5 * 60 * 1000;

                    while (Date.now() - startedAt < timeoutMs) {
                        await new Promise((resolve) => setTimeout(resolve, 1500));

                        const res = await fetch(`/domain-search/ai-status/${jobId}`, {
                            headers: this.headers(),
                        });

                        const data = await res.json();

                        if (!res.ok) {
                            this.error = data.error || 'Failed to retrieve AI generation status.';
                            return;
                        }

                        if (data.status === 'pending' || data.status === 'processing') {
                            this.statusText = data.status === 'processing'
                                ? 'Generating AI suggestions...'
                                : 'Queued for AI generation...';
                            continue;
                        }

                        if (data.status === 'failed') {
                            this.error = data.error || 'AI generation failed.';
                            return;
                        }

                        if (data.status === 'completed') {
                            this.aiUsage = data.usage || null;
                            this.aiCostText = this.computeAiCost(this.aiUsage);

                            const domains = data.domains || [];
                            if (domains.length === 0) {
                                this.error = data.error || 'No domain names could be generated.';
                                return;
                            }

                            this.results = this.sortResults(data.results || []);
                            this.statusText = 'Done!';
                            if (data.error) {
                                this.error = data.error;
                            }
                            return;
                        }
                    }

                    this.error = 'AI generation timed out. Please try again.';
                },

                async searchAI() {
                    const promptVal = this.aiPrompt.trim();
                    if (!promptVal) return;

                    this.loading = true;
                    this.error = null;
                    this.results = [];
                    this.searched = true;
                    this.filter = 'all';
                    this.filterExt = '';
                    this.statusText = 'Generating AI suggestions...';

                    try {
                        const aiResponse = await fetch('/domain-search/ai-generate', {
                            method: 'POST',
                            headers: this.headers(),
                            body: JSON.stringify({
                                prompt: promptVal,
                                tlds: this.tlds,
                                prompt_modifier: this.promptModifier || 'none',
                                excluded: this.excluded,
                            }),
                        });

                        if (aiResponse.status === 419) {
                            this.error = 'Session expired. Please refresh the page and try again.';
                            return;
                        }

                        const aiData = await aiResponse.json();

                        if (!aiResponse.ok) {
                            if (aiResponse.status === 429 && aiData.authenticated === false) {
                                this.error = aiData.error || 'Unlimited domain search with account, otherwise 25 free AI Generator requests per day';
                                return;
                            }
                            if (aiResponse.status === 401 && aiData.authenticated === false) {
                                this.error = aiData.error || 'You must be logged in to use AI Generator.';
                                return;
                            }
                            if (aiData.errors) {
                                const firstKey = Object.keys(aiData.errors)[0];
                                this.error = aiData.errors[firstKey]?.[0] || 'Validation error.';
                                return;
                            }
                            this.error = aiData.error || 'Failed to generate domains with AI.';
                            return;
                        }

                        if (!aiData.job_id) {
                            this.error = 'AI queue did not return a job ID.';
                            return;
                        }

                        if (this.remainingAiRequests > 0) {
                            this.remainingAiRequests--;
                        }

                        this.statusText = 'Queued for AI generation...';
                        await this.pollAiGeneration(aiData.job_id);
                        return;

                    } catch (e) {
                        this.error = 'Network error. Please try again.';
                    } finally {
                        this.loading = false;
                    }
                },
            };
        }
    </script>
</body>
</html>
