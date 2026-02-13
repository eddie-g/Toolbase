<!DOCTYPE html>
<html lang="en" x-data="{
    darkMode: localStorage.getItem('darkMode') === 'true' || false,
    toggleDarkMode() {
        this.darkMode = !this.darkMode;
        localStorage.setItem('darkMode', this.darkMode);
    }
}" x-init="$watch('darkMode', val => document.documentElement.classList.toggle('dark', val))" :class="{ 'dark': darkMode }">
<head>
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
<body class="bg-gray-50 dark:bg-gray-950 antialiased min-h-screen">

    <!-- Header -->
    <x-site-header :compact="true" :show-navigation="false" :show-auth-controls="false" brand="NetKit" />

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
            </div>

            <!-- Mode Tabs -->
            <div class="flex bg-gray-100 dark:bg-gray-800 rounded-xl p-1 mb-8">
                <button
                    @click="mode = 'direct'"
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
                    @click="mode = 'generate'"
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
                    @click="mode = 'ai'"
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
                        <div class="flex items-center justify-between mb-2">
                            <label for="domain-input" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Domain Names</label>
                            
                            <!-- Live Search Toggle -->
                            <label class="flex items-center gap-2 cursor-pointer group">
                                <span class="text-xs font-medium text-gray-600 dark:text-gray-400 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition">Live search</span>
                                <div class="relative">
                                    <input type="checkbox" x-model="liveSearch" class="sr-only" />
                                    <div class="w-10 h-6 rounded-full transition-colors"
                                         :class="liveSearch ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-700'">
                                        <div class="absolute left-1 top-1 w-4 h-4 bg-white rounded-full shadow-sm transition-transform duration-200 ease-in-out"
                                             :class="liveSearch ? 'transform translate-x-4' : ''"></div>
                                    </div>
                                </div>
                            </label>
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
                                @input="handleLiveSearch()"
                                @keydown.enter.prevent="searchDirect()"
                                placeholder="e.g.  coolstartup, myapp, brandname"
                                class="w-full pl-12 pr-4 py-3.5 rounded-xl border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition text-base"
                                autofocus
                            />
                        </div>
                        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500" x-show="!liveSearch">Separate multiple names with commas or spaces. No TLD needed.</p>
                        <p class="mt-2 text-xs flex items-center gap-1.5 text-blue-600 dark:text-blue-400" x-show="liveSearch">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                            <span>Live search enabled - results appear as you type</span>
                        </p>
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

                    <button type="submit" :disabled="loading || !query.trim() || tlds.length === 0"
                        class="w-full py-3.5 px-6 rounded-xl bg-blue-600 hover:bg-blue-700 disabled:bg-gray-300 dark:disabled:bg-gray-700 disabled:cursor-not-allowed text-white font-semibold text-base transition flex items-center justify-center gap-2">
                        <template x-if="loading">
                            <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </template>
                        <span x-text="loading ? 'Checking...' : 'Check Availability'"></span>
                    </button>
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
                        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                            Pulls the top 10 highest-scoring words from <code class="text-[11px]">dictionary.<span x-text="selectedCategory"></span></code> and builds:
                            <span class="font-semibold text-gray-500 dark:text-gray-300" x-text="(prefixWord || '[prefix]') + '[word]' + (suffixWord || '[suffix]')"></span>
                        </p>
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
                </form>

                <!-- AI Generator Mode -->
                <form x-show="mode === 'ai'" @submit.prevent="searchAI()">
                    @auth
                        <div class="mb-5">
                            <label for="ai-prompt" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Describe Your Domain Idea</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                                    </svg>
                                </div>
                                <textarea
                                    id="ai-prompt"
                                    x-model="aiPrompt"
                                    rows="3"
                                    placeholder="e.g., I need a domain for a modern fitness tracking app that focuses on mindfulness and wellness..."
                                    class="w-full pl-12 pr-4 py-3.5 rounded-xl border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-base resize-none"
                                ></textarea>
                            </div>
                            <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">Powered by Google Gemini. Describe your business, target audience, or domain style for personalized suggestions.</p>
                        </div>

                        <!-- Costs -->
                        <div class="mb-5">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Costs</label>
                            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-4 py-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-600 dark:text-gray-400">Estimated cost per request</span>
                                    <span class="text-sm font-semibold text-gray-900 dark:text-white" x-text="aiCostText"></span>
                                </div>
                                <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">Based on Gemini 2.0 Flash pricing: $0.10 / 1M input tokens, $0.40 / 1M output tokens.</p>
                            </div>
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
                    @else
                        <div class="text-center py-12">
                            <svg class="mx-auto h-16 w-16 text-indigo-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Login Required</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Please log in to use the AI Generator feature</p>
                            <a href="/admin/login" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold transition">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                                </svg>
                                Log In
                            </a>
                        </div>
                    @endauth
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
                            <span x-text="mode === 'generate' ? 'Generated Results' : 'Results'"></span>
                        </h2>
                        <div class="flex items-center gap-4">
                            <div class="flex items-center gap-3 text-sm">
                                <span class="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                    <span x-text="results.filter(r => r.available).length"></span> available
                                </span>
                                <span class="flex items-center gap-1.5 text-gray-400 dark:text-gray-500">
                                    <span class="w-2 h-2 rounded-full bg-gray-400 dark:bg-gray-600"></span>
                                    <span x-text="results.filter(r => r.taken).length"></span> taken
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Buttons -->
                    <div class="flex gap-2 mb-4">
                        <button @click="filter = 'all'"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg border transition"
                            :class="filter === 'all'
                                ? 'bg-gray-900 dark:bg-white text-white dark:text-gray-900 border-transparent'
                                : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300'">
                            All <span class="ml-1 opacity-60" x-text="results.length"></span>
                        </button>
                        <button @click="filter = 'available'"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg border transition"
                            :class="filter === 'available'
                                ? 'bg-emerald-600 text-white border-transparent'
                                : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300'">
                            Available <span class="ml-1 opacity-60" x-text="results.filter(r => r.available).length"></span>
                        </button>
                        <button @click="filter = 'for_sale'"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg border transition"
                            :class="filter === 'for_sale'
                                ? 'bg-orange-500 text-white border-transparent'
                                : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300'">
                            For Sale <span class="ml-1 opacity-60" x-text="results.filter(r => r.for_sale).length"></span>
                        </button>
                        <button @click="filter = 'taken'"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg border transition"
                            :class="filter === 'taken'
                                ? 'bg-gray-500 text-white border-transparent'
                                : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300'">
                            Taken <span class="ml-1 opacity-60" x-text="results.filter(r => r.taken && !r.for_sale).length"></span>
                        </button>
                    </div>
                </div>
            </template>

            <!-- Results List -->
            <template x-if="filteredResults.length > 0">
                <div class="space-y-2">
                    <template x-for="(result, index) in filteredResults" :key="`${result.domain}-${index}`">
                        <div
                            class="result-enter group flex items-center justify-between p-4 rounded-xl border transition-all"
                            :class="result.available
                                ? 'bg-emerald-50 dark:bg-emerald-900/10 border-emerald-200 dark:border-emerald-800/50 hover:shadow-md hover:shadow-emerald-100 dark:hover:shadow-emerald-900/20'
                                : result.for_sale
                                    ? 'bg-orange-50 dark:bg-orange-900/10 border-orange-200 dark:border-orange-800/50 hover:shadow-md hover:shadow-orange-100 dark:hover:shadow-orange-900/20'
                                    : result.error
                                        ? 'bg-amber-50 dark:bg-amber-900/10 border-amber-200 dark:border-amber-800/50'
                                        : 'bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-800 opacity-70'"
                        >
                            <div class="flex items-center gap-3">
                                <div class="shrink-0">
                                    <template x-if="result.available">
                                        <div class="w-10 h-10 rounded-full bg-emerald-100 dark:bg-emerald-800/40 flex items-center justify-center">
                                            <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                                            </svg>
                                        </div>
                                    </template>
                                    <template x-if="result.for_sale">
                                        <div class="w-10 h-10 rounded-full bg-orange-100 dark:bg-orange-800/40 flex items-center justify-center">
                                            <svg class="w-5 h-5 text-orange-600 dark:text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                        </div>
                                    </template>
                                    <template x-if="result.taken && !result.for_sale">
                                        <div class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                            <svg class="w-5 h-5 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"></path>
                                            </svg>
                                        </div>
                                    </template>
                                    <template x-if="result.error">
                                        <div class="w-10 h-10 rounded-full bg-amber-100 dark:bg-amber-800/40 flex items-center justify-center">
                                            <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.632c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                                            </svg>
                                        </div>
                                    </template>
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-900 dark:text-white text-base" x-text="result.domain"></p>
                                    <p class="text-xs mt-0.5"
                                       :class="result.available
                                           ? 'text-emerald-600 dark:text-emerald-400'
                                           : result.for_sale
                                               ? 'text-orange-600 dark:text-orange-400'
                                               : result.error
                                                   ? 'text-amber-600 dark:text-amber-400'
                                                   : 'text-gray-400 dark:text-gray-500'"
                                       x-text="result.available ? 'Available!' : result.for_sale ? 'For Sale' : result.error ? result.error : 'Registered'">
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="text-xs font-medium px-2.5 py-1 rounded-lg"
                                      :class="tldBadgeClass(result.tld)"
                                      x-text="result.tld">
                                </span>
                                <template x-if="result.available">
                                    <a :href="'https://www.namecheap.com/domains/registration/results/?domain=' + result.domain"
                                       target="_blank"
                                       class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-medium transition">
                                        Register
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                        </svg>
                                    </a>
                                </template>
                                <template x-if="result.available">
                                    <button type="button"
                                        @click="toggleExclude(result.domain)"
                                        class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border text-xs font-medium transition"
                                        :class="isExcluded(result.domain)
                                            ? 'bg-gray-900 dark:bg-white text-white dark:text-gray-900 border-transparent'
                                            : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300'">
                                        <span x-text="isExcluded(result.domain) ? 'Excluded' : 'Exclude'"></span>
                                    </button>
                                </template>
                                <template x-if="result.for_sale">
                                    <a :href="'https://www.namecheap.com/domains/marketplace/?query=' + result.domain"
                                       target="_blank"
                                       class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-orange-500 hover:bg-orange-600 text-white text-xs font-medium transition">
                                        View Listing
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                        </svg>
                                    </a>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            <!-- Empty State -->
            <template x-if="!loading && results.length === 0 && searched">
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
                query: '',
                prefixWord: '',
                suffixWord: '',
                selectedCategory: 'tech',
                categoryOptions: [
                    { value: 'space', label: 'Space' },
                    { value: 'tech', label: 'Tech' },
                    { value: 'fantasy', label: 'Fantasy' },
                    { value: 'scifi', label: 'Sci-Fi' },
                    { value: 'romance', label: 'Romance' },
                    { value: 'mystery', label: 'Mystery' },
                    { value: 'thriller', label: 'Thriller' },
                    { value: 'horror', label: 'Horror' },
                    { value: 'adventure', label: 'Adventure' },
                    { value: 'historical', label: 'Historical' },
                    { value: 'drama', label: 'Drama' },
                    { value: 'action', label: 'Action' },
                ],
                aiPrompt: '',
                aiUsage: null,
                aiCostText: '$0.00',
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
                progress: 0,
                statusText: 'Starting...',
                liveSearch: false,
                liveSearchTimeout: null,
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
                        if (a.available !== b.available) return a.available ? -1 : 1;
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

                buildAiPrompt(basePrompt) {
                    const trimmedPrompt = (basePrompt || '').trim();
                    if (this.excluded.length === 0) return trimmedPrompt;

                    const prefix = "\n\nand it's NOT ";
                    return trimmedPrompt + prefix + this.excluded.join(', ');
                },

                get filteredResults() {
                    if (this.filter === 'available') return this.results.filter(r => r.available);
                    if (this.filter === 'for_sale') return this.results.filter(r => r.for_sale);
                    if (this.filter === 'taken') return this.results.filter(r => r.taken && !r.for_sale);
                    return this.results;
                },

                headers() {
                    return {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        'Accept': 'application/json',
                    };
                },

                handleLiveSearch() {
                    // Only trigger if live search is enabled
                    if (!this.liveSearch) return;

                    // Clear any existing timeout
                    if (this.liveSearchTimeout) {
                        clearTimeout(this.liveSearchTimeout);
                    }

                    // Debounce: wait 500ms after user stops typing
                    this.liveSearchTimeout = setTimeout(() => {
                        const trimmedQuery = this.query.trim();
                        if (trimmedQuery && this.tlds.length > 0) {
                            this.searchDirect();
                        } else if (!trimmedQuery) {
                            // Clear results if query is empty
                            this.results = [];
                            this.searched = false;
                            this.error = null;
                        }
                    }, 500);
                },

                async searchDirect() {
                    if (!this.query.trim() || this.tlds.length === 0) return;

                    this.loading = true;
                    this.error = null;
                    this.results = [];
                    this.searched = true;
                    this.filter = 'all';

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
                        if (data.error) {
                            this.error = data.error;
                            this.loading = false;
                            return;
                        }

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

                async searchAI() {
                    const promptVal = this.aiPrompt.trim();
                    if (!promptVal) return;

                    const finalPrompt = this.buildAiPrompt(promptVal);

                    this.loading = true;
                    this.error = null;
                    this.results = [];
                    this.searched = true;
                    this.filter = 'all';
                    this.statusText = 'Generating AI suggestions...';

                    let streamMode = false;

                    try {
                        // Step 1: Generate names with AI
                        const aiResponse = await fetch('/domain-search/ai-generate', {
                            method: 'POST',
                            headers: this.headers(),
                            body: JSON.stringify({
                                prompt: finalPrompt,
                                tlds: this.tlds,
                                stream: true,
                            }),
                        });

                        // Handle 419 CSRF error specifically
                        if (aiResponse.status === 419) {
                            this.error = 'Session expired. Please refresh the page and try again.';
                            setTimeout(() => {
                                window.location.reload();
                            }, 2000);
                            return;
                        }

                        const aiData = await aiResponse.json();

                        if (!aiResponse.ok) {
                            // Handle authentication error specifically
                            if (aiResponse.status === 401 && aiData.authenticated === false) {
                                this.error = aiData.error || 'You must be logged in to use AI Generator.';
                                // Redirect to login after a short delay
                                setTimeout(() => {
                                    window.location.href = '/admin/login?redirect=' + encodeURIComponent(window.location.pathname);
                                }, 2000);
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

                        const domains = aiData.domains || [];
                        this.aiUsage = aiData.usage || null;
                        this.aiCostText = this.computeAiCost(this.aiUsage);

                        if (domains.length === 0) {
                            this.error = 'No domain names could be generated.';
                            return;
                        }

                        this.statusText = `Checking ${domains.length} domains...`;
                        this.results = [];
                        this.startAvailabilityPolling(domains);
                        streamMode = true;
                        return;

                    } catch (e) {
                        this.error = 'Network error. Please try again.';
                    } finally {
                        if (!streamMode) {
                            this.loading = false;
                        }
                    }
                },
            };
        }
    </script>
</body>
</html>
