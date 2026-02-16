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
    <title>Logo Generator - Netkit</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        [x-cloak] { display: none !important; }
        .result-enter { animation: resultSlide 0.2s ease-out; }
        @keyframes resultSlide {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-950 antialiased min-h-screen">

    <x-site-header :compact="true" :show-navigation="false" :show-auth-controls="false" brand="NetKit" />

    <main class="pt-28 pb-20" x-data="logoGenerator()">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8 max-w-[1000px]">
            <div class="text-center mb-10">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-emerald-100 dark:bg-emerald-900/40 rounded-2xl mb-5">
                    <svg class="w-8 h-8 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                </div>
                <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 dark:text-white mb-3">AI Logo Generator</h1>
                <p class="text-gray-500 dark:text-gray-400 text-lg">Generate brand logos and reuse any result as your next prompt</p>
                <div class="mt-4">
                    <a href="{{ route('domainSearch.index') }}" class="inline-flex items-center gap-2 text-sm text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition font-medium">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                        Back to Domain Search
                    </a>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-lg shadow-gray-200/50 dark:shadow-black/20 border border-gray-200 dark:border-gray-800 p-6 mb-8">
                @auth
                    {{-- Text in Logo toggle --}}
                    <div class="flex items-center justify-between mb-5">
                        <span class="text-base font-semibold text-gray-800 dark:text-gray-200">Text in Logo</span>
                        <button type="button" @click="logoIconOnly = !logoIconOnly"
                            class="relative inline-flex h-9 w-16 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900"
                            :class="!logoIconOnly ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600'"
                            role="switch" :aria-checked="(!logoIconOnly).toString()">
                            <span class="pointer-events-none relative inline-block h-7 w-7 transform rounded-full bg-white shadow ring-0 transition-transform duration-200 ease-in-out"
                                :class="!logoIconOnly ? 'translate-x-7' : 'translate-x-0.5'" style="top: 1px;">
                                <span class="absolute inset-0 flex h-full w-full items-center justify-center transition-opacity duration-200"
                                    :class="!logoIconOnly ? 'opacity-0' : 'opacity-100'" aria-hidden="true">
                                    <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 12 12"><path d="M4 8l2-2m0 0l2-2M6 6L4 4m2 2l2 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </span>
                                <span class="absolute inset-0 flex h-full w-full items-center justify-center transition-opacity duration-200"
                                    :class="!logoIconOnly ? 'opacity-100' : 'opacity-0'" aria-hidden="true">
                                    <svg class="h-4 w-4 text-emerald-600" fill="currentColor" viewBox="0 0 12 12"><path d="M3.707 5.293a1 1 0 00-1.414 1.414l1.414-1.414zM5 8l-.707.707a1 1 0 001.414 0L5 8zm4.707-3.293a1 1 0 00-1.414-1.414l1.414 1.414zm-7.414 2l2 2 1.414-1.414-2-2-1.414 1.414zm3.414 2l4-4-1.414-1.414-4 4 1.414 1.414z"/></svg>
                                </span>
                            </span>
                        </button>
                    </div>

                    <div class="mb-5" x-show="!logoIconOnly" x-transition>
                        <label for="logo-domain" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Domain Name</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path>
                                </svg>
                            </div>
                            <input
                                id="logo-domain"
                                type="text"
                                x-model="logoDomain"
                                @keydown.enter.prevent="generateLogo()"
                                placeholder="e.g. coolstartup, myapp, brandname"
                                class="w-full pl-12 pr-4 py-3.5 rounded-xl border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition text-base"
                            />
                        </div>
                        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">Enter a brand or domain name to generate a logo for.</p>
                    </div>

                    <div class="mb-5">
                        <label for="logo-prompt" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Describe Your Logo <span class="text-gray-400 font-normal">(optional)</span></label>
                        <textarea
                            id="logo-prompt"
                            x-model="logoPrompt"
                            rows="2"
                            placeholder="e.g. a rocket launching into space, a shield with a lion, abstract waves..."
                            class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition text-sm resize-none"
                        ></textarea>
                        <p class="mt-1.5 text-xs text-gray-400 dark:text-gray-500">Click <span class="font-semibold">Use as Prompt</span> on a generated logo to quickly iterate.</p>
                    </div>

                    <div class="mb-6" x-show="logoPrompt.trim().length >= 3 || similarIdeasLoading || similarIdeasError || similarIdeas.length > 0">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Other Ideas</h3>
                            <span class="text-xs text-gray-400 dark:text-gray-500" x-show="similarIdeas.length > 0" x-text="similarIdeas.length + ' similar saved ideas'"></span>
                        </div>

                        <div x-show="similarIdeasLoading" class="text-xs text-gray-500 dark:text-gray-400 mb-2">Looking for similar saved icon ideas...</div>

                        <div x-show="similarIdeasError" class="mb-2 p-2 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 text-xs text-red-700 dark:text-red-300" x-text="similarIdeasError"></div>

                        <div x-show="!similarIdeasLoading && !similarIdeasError && logoPrompt.trim().length >= 3 && similarIdeas.length === 0" class="text-xs text-gray-500 dark:text-gray-400">No similar saved icon ideas found yet.</div>

                        <div x-show="similarIdeas.length > 0" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <template x-for="idea in similarIdeas" :key="idea.id">
                                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 overflow-hidden">
                                    <div class="aspect-video bg-white dark:bg-gray-900 flex items-center justify-center p-2">
                                        <img :src="idea.image_urls[0]" alt="Similar logo idea" class="max-w-full max-h-full object-contain" />
                                    </div>
                                    <div class="p-3">
                                        <p class="text-xs text-gray-700 dark:text-gray-300 leading-relaxed line-clamp-3" x-text="idea.prompt"></p>
                                        <div class="mt-2 flex items-center justify-between gap-2">
                                            <span class="text-[11px] text-gray-400 dark:text-gray-500" x-text="'Similarity ' + Math.round((idea.score || 0) * 100) + '%'"></span>
                                            <div class="flex items-center gap-1.5">
                                                @if(config('services.logo_editor_enabled'))
                                                <a :href="'/logos/' + idea.id + '/edit?image=0'" class="px-2.5 py-1 rounded-lg bg-green-600 text-white text-xs font-medium hover:bg-green-700 transition">Open in Editor</a>
                                                @endif
                                                <button type="button" class="px-2.5 py-1 rounded-lg bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-300 text-xs font-medium hover:bg-violet-200 dark:hover:bg-violet-800/40 transition" @click="logoPrompt = idea.prompt">Use Prompt</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Logo Style</label>
                        <div class="flex gap-2">
                            <button type="button" @click="logoStyle = 'professional'; fetchLogoPrice()" class="flex-1 px-3 py-2.5 rounded-xl border-2 text-xs font-medium transition-all text-center" :class="logoStyle === 'professional' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-600'">Professional</button>
                            <button type="button" @click="logoStyle = 'fantasy'; fetchLogoPrice()" class="flex-1 px-3 py-2.5 rounded-xl border-2 text-xs font-medium transition-all text-center" :class="logoStyle === 'fantasy' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-600'">Fantasy</button>
                            <button type="button" @click="logoStyle = 'future'; fetchLogoPrice()" class="flex-1 px-3 py-2.5 rounded-xl border-2 text-xs font-medium transition-all text-center" :class="logoStyle === 'future' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-600'">Future</button>
                            <button type="button" @click="logoStyle = 'vector'; fetchLogoPrice()" class="flex-1 px-3 py-2.5 rounded-xl border-2 text-xs font-medium transition-all text-center" :class="logoStyle === 'vector' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-600'">Vector</button>
                        </div>
                    </div>

                    <div class="mb-5">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-2">Background</label>
                            <div class="flex gap-1.5 items-center">
                                <button type="button" @click="logoBgColor = 'white'; fetchLogoPrice()" class="w-8 h-8 rounded-lg border-2 bg-white transition-all flex-shrink-0" :class="logoBgColor === 'white' ? 'border-emerald-500 ring-2 ring-emerald-200' : 'border-gray-300 dark:border-gray-600'" title="White"></button>
                                <button type="button" @click="logoBgColor = 'black'; fetchLogoPrice()" class="w-8 h-8 rounded-lg border-2 bg-black transition-all flex-shrink-0" :class="logoBgColor === 'black' ? 'border-emerald-500 ring-2 ring-emerald-200' : 'border-gray-300 dark:border-gray-600'" title="Black"></button>
                                <button type="button" @click="logoBgColor = 'transparent'; fetchLogoPrice()" class="w-8 h-8 rounded-lg border-2 transition-all flex-shrink-0 relative overflow-hidden" :class="logoBgColor === 'transparent' ? 'border-emerald-500 ring-2 ring-emerald-200' : 'border-gray-300 dark:border-gray-600'" title="Transparent" style="background: repeating-conic-gradient(#ccc 0% 25%, white 0% 50%) 50% / 12px 12px"></button>
                                <div class="relative flex-shrink-0">
                                    <button type="button" @click="logoBgColor = 'custom'; fetchLogoPrice()" class="w-8 h-8 rounded-lg border-2 transition-all overflow-hidden" :class="logoBgColor === 'custom' ? 'border-emerald-500 ring-2 ring-emerald-200' : 'border-gray-300 dark:border-gray-600'" :style="'background-color: ' + logoBgCustom" title="Custom color"></button>
                                    <input type="color" x-model="logoBgCustom" @input="logoBgColor = 'custom'; fetchLogoPrice()" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-5">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Number of Logos</label>
                        <div class="grid grid-cols-4 gap-3">
                            <template x-for="n in [1, 2, 3, 4]" :key="n">
                                <button type="button" @click="logoCount = n; fetchLogoPrice()" class="py-2.5 rounded-xl border-2 text-sm font-semibold transition-all text-center" :class="logoCount === n ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-600'" x-text="n"></button>
                            </template>
                        </div>
                    </div>

                    <div class="mb-5">
                        <div class="flex items-center justify-between p-3.5 rounded-xl border-2 transition-all cursor-pointer" :class="logoProMode ? 'border-amber-400 bg-gradient-to-r from-amber-50 to-orange-50 dark:from-amber-900/20 dark:to-orange-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'" @click="logoProMode = !logoProMode; fetchLogoPrice()">
                            <div class="flex items-center gap-3">
                                <div class="text-sm font-semibold text-gray-900 dark:text-white">PRO Mode</div>
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider" :class="logoProMode ? 'bg-amber-500 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-500 dark:text-gray-400'" x-text="logoProMode ? 'ON' : 'OFF'"></span>
                            </div>
                            <div class="relative">
                                <div class="w-11 h-6 rounded-full transition-colors" :class="logoProMode ? 'bg-amber-500' : 'bg-gray-300 dark:bg-gray-600'"><div class="absolute top-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" :class="logoProMode ? 'translate-x-[22px]' : 'translate-x-0.5'"></div></div>
                            </div>
                        </div>
                        <div x-show="logoProMode" x-transition class="mt-3">
                            <div class="grid grid-cols-3 gap-2">
                                <button type="button" @click="logoProSize = '512'; fetchLogoPrice()" class="py-2 rounded-lg border text-xs font-semibold transition" :class="logoProSize === '512' ? 'border-amber-500 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400'">512</button>
                                <button type="button" @click="logoProSize = '1024'; fetchLogoPrice()" class="py-2 rounded-lg border text-xs font-semibold transition" :class="logoProSize === '1024' ? 'border-amber-500 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400'">1024</button>
                                <button type="button" @click="logoProSize = '1536'; fetchLogoPrice()" class="py-2 rounded-lg border text-xs font-semibold transition" :class="logoProSize === '1536' ? 'border-amber-500 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400'">1536</button>
                            </div>
                        </div>
                    </div>

                    <div class="mb-5 p-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium text-gray-700 dark:text-gray-300">Estimated Cost</span>
                            <span class="font-bold text-gray-900 dark:text-white" x-text="logoPriceLoading ? 'Fetching...' : ('$' + logoCostTotal.toFixed(4) + ' USD')"></span>
                        </div>
                        <div class="mt-2 flex items-center justify-between text-xs text-gray-400 dark:text-gray-500">
                            <span x-text="logoCount + ' image' + (logoCount > 1 ? 's' : '') + ' × $' + logoCostPerImage.toFixed(4) + '/each'"></span>
                            <span x-text="logoProMode ? 'Flux Pro · ' + logoProSize + '×' + logoProSize : 'Flux Schnell · 512×512'"></span>
                        </div>
                    </div>

                    <button type="button" @click="generateLogo()" :disabled="logoLoading || (!logoIconOnly && !logoDomain.trim())"
                        class="w-full py-3.5 px-6 rounded-xl font-semibold text-base transition flex items-center justify-center gap-2 disabled:cursor-not-allowed text-white"
                        :class="logoProMode
                            ? 'bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 disabled:from-gray-300 disabled:to-gray-300 dark:disabled:from-gray-700 dark:disabled:to-gray-700'
                            : 'bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 disabled:from-gray-300 disabled:to-gray-300 dark:disabled:from-gray-700 dark:disabled:to-gray-700'">
                        <template x-if="logoLoading">
                            <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                        </template>
                        <span x-text="logoLoading ? 'Generating...' : (logoProMode ? '★ ' : '') + 'Generate ' + logoCount + (logoProMode ? ' PRO' : '') + ' Logo' + (logoCount > 1 ? 's' : '')"></span>
                    </button>

                    <div x-show="logoImages.length > 0" class="mt-6">
                        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Generated Logos</h3>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <template x-for="(img, idx) in logoImages" :key="idx">
                                <div class="rounded-xl overflow-hidden border border-gray-200 dark:border-gray-700 shadow-sm">
                                    <div class="aspect-square cursor-zoom-in"
                                        @click="zoomLogo(idx)"
                                        :style="logoBgResult === 'transparent'
                                            ? 'background: repeating-conic-gradient(#d1d5db 0% 25%, #f3f4f6 0% 50%) 50% / 20px 20px'
                                            : logoBgResult.startsWith('#')
                                                ? 'background-color: ' + logoBgResult
                                                : logoBgResult === 'black'
                                                    ? 'background-color: #000'
                                                    : 'background-color: #fff'">
                                        <img :src="img.url" :alt="'Logo ' + (idx+1)" class="w-full h-full object-cover" />
                                    </div>
                                    <div class="p-2 flex gap-1.5 flex-wrap">
                                        <a :href="img.url" :download="logoDomain + '-logo-' + (idx+1) + '.png'" target="_blank" class="flex-1 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition text-center">Save</a>
                                        <a x-show="img.svg_url" :href="img.svg_url" :download="logoDomain + '-logo-' + (idx+1) + '.svg'" target="_blank" class="flex-1 py-1.5 rounded-lg bg-emerald-100 dark:bg-emerald-900/30 text-xs font-medium text-emerald-700 dark:text-emerald-300 hover:bg-emerald-200 dark:hover:bg-emerald-800/40 transition text-center">SVG</a>
                                        @if(config('services.logo_editor_enabled'))
                                        <a :href="logoEditorUrl(idx, img)"
                                           class="flex-1 py-1.5 rounded-lg bg-green-600 text-xs font-medium text-white hover:bg-green-700 transition text-center"
                                           :class="{ 'opacity-50 pointer-events-none': !logoRequestId }">
                                           Open in Editor
                                        </a>
                                        @endif
                                        <button type="button" @click.stop.prevent="describeLogo(idx)" :disabled="img.describing"
                                            class="w-full py-1.5 rounded-lg text-xs font-medium transition flex items-center justify-center gap-1 cursor-pointer relative z-10 disabled:cursor-not-allowed"
                                            :class="img.describing
                                                ? 'bg-violet-200 dark:bg-violet-900/50 text-violet-400'
                                                : 'bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-300 hover:bg-violet-200 dark:hover:bg-violet-800/40'">
                                            <svg x-show="img.describing" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                            </svg>
                                            <svg x-show="!img.describing" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                            <span x-text="img.describing ? 'Analyzing...' : 'Use as Prompt'"></span>
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div x-show="zoomedLogoUrl" x-cloak x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4" @click.self="zoomedLogoUrl = null" @keydown.escape.window="zoomedLogoUrl = null">
                        <div class="relative max-w-3xl w-full bg-white dark:bg-gray-900 rounded-2xl shadow-2xl overflow-hidden" @click.stop>
                            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                                <h4 class="text-sm font-semibold text-gray-900 dark:text-white">Logo Preview</h4>
                                <button @click="zoomedLogoUrl = null" class="p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                                    <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </button>
                            </div>
                            <div class="p-4 flex items-center justify-center" :style="logoBgResult === 'transparent' ? 'background: repeating-conic-gradient(#d1d5db 0% 25%, #f3f4f6 0% 50%) 50% / 20px 20px' : logoBgResult.startsWith('#') ? 'background-color: ' + logoBgResult : logoBgResult === 'black' ? 'background-color: #000' : 'background-color: #f9fafb'">
                                <img :src="zoomedLogoUrl" alt="Logo full view" class="max-w-full max-h-[70vh] object-contain" />
                            </div>
                        </div>
                    </div>

                    <template x-if="logoError">
                        <div class="mt-4 p-4 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 text-sm">
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <span x-text="logoError"></span>
                            </div>
                        </div>
                    </template>
                @else
                    <div class="text-center py-12">
                        <svg class="mx-auto h-16 w-16 text-emerald-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                        </svg>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Login Required</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Please log in to use the AI Logo Generator</p>
                        <a href="/admin/login" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold transition">Log In</a>
                    </div>
                @endauth
            </div>
        </div>
    </main>

    <script>
        function logoGenerator() {
            return {
                logoDomain: '',
                logoPrompt: '',
                logoStyle: 'professional',
                logoCount: 4,
                logoCostPerImage: 0.003,
                logoCostTotal: 0.012,
                logoCostSource: 'loading',
                logoPriceLoading: false,
                logoLoading: false,
                logoImages: [],
                logoError: null,
                zoomedLogoUrl: null,
                logoProMode: false,
                logoProSize: '1024',
                logoIconOnly: false,
                logoBgColor: 'white',
                logoBgCustom: '#ffffff',
                logoBgResult: 'white',
                logoRequestId: null,
                similarIdeas: [],
                similarIdeasLoading: false,
                similarIdeasError: null,
                similarIdeasDebounce: null,

                init() {
                    this.fetchLogoPrice();
                    this.$watch('logoPrompt', (val) => this.queueSimilarIdeasLookup(val));
                },

                headers() {
                    return {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        'Accept': 'application/json',
                    };
                },

                setLogoImageState(idx, patch) {
                    if (idx === undefined || idx === null) return;
                    const existing = this.logoImages[idx];
                    if (!existing) return;
                    this.logoImages.splice(idx, 1, { ...existing, ...patch });
                },

                async fetchLogoPrice() {
                    this.logoPriceLoading = true;
                    try {
                        const bgColor = this.logoBgColor === 'custom' ? this.logoBgCustom : this.logoBgColor;
                        const response = await fetch('/domain-search/estimate-logo-price', {
                            method: 'POST',
                            headers: this.headers(),
                            body: JSON.stringify({
                                count: this.logoCount,
                                pro: this.logoProMode,
                                pro_size: this.logoProMode ? parseInt(this.logoProSize) : 512,
                                style: this.logoStyle,
                                bg_color: bgColor,
                            }),
                        });
                        if (response.ok) {
                            const data = await response.json();
                            this.logoCostPerImage = parseFloat(data.cost_per_image) || 0.003;
                            this.logoCostTotal = parseFloat(data.estimated_cost_usd) || (this.logoCount * 0.003);
                            this.logoCostSource = data.source || 'fallback';
                        }
                    } catch (e) {
                    } finally {
                        this.logoPriceLoading = false;
                    }
                },

                async generateLogo() {
                    const domain = this.logoDomain.trim();
                    if (!domain && !this.logoIconOnly) return;

                    this.logoLoading = true;
                    this.logoError = null;
                    this.logoImages = [];
                    this.logoRequestId = null;
                    this.zoomedLogoUrl = null;

                    try {
                        const response = await fetch('/domain-search/generate-logo', {
                            method: 'POST',
                            headers: this.headers(),
                            body: JSON.stringify({
                                domain: domain,
                                style: this.logoStyle,
                                count: this.logoCount,
                                custom_prompt: this.logoPrompt.trim() || null,
                                pro: this.logoProMode,
                                pro_size: this.logoProMode ? parseInt(this.logoProSize) : null,
                                icon_only: this.logoIconOnly,
                                bg_color: this.logoBgColor === 'custom' ? this.logoBgCustom : this.logoBgColor,
                            }),
                        });

                        if (response.status === 419) {
                            this.logoError = 'Session expired. Please refresh the page and try again.';
                            return;
                        }

                        const data = await response.json();

                        if (!response.ok) {
                            this.logoError = data.error || 'Failed to generate logo.';
                            return;
                        }

                        this.logoRequestId = data.logo_request_id || null;
                        this.logoImages = (data.images || []).map((img) => ({ ...img, describing: false }));
                        this.logoBgResult = data.bg_color || 'white';
                        if (this.logoImages.length === 0) {
                            this.logoError = 'No logo was generated. Please try again.';
                        }
                    } catch (e) {
                        this.logoError = 'Network error. Please try again.';
                    } finally {
                        this.logoLoading = false;
                    }
                },

                zoomLogo(idx) {
                    if (idx === undefined || !this.logoImages[idx]) return;
                    this.zoomedLogoUrl = this.logoImages[idx].url;
                },

                logoEditorUrl(idx, img) {
                    if (!this.logoRequestId) return '#';
                    const url = new URL(`/logos/${this.logoRequestId}/edit`, window.location.origin);
                    url.searchParams.set('image', String(idx || 0));
                    return url.pathname + url.search;
                },

                async describeLogo(idx) {
                    const img = this.logoImages[idx];
                    if (!img || img.describing) return;

                    this.setLogoImageState(idx, { describing: true });

                    try {
                        const response = await fetch('/domain-search/describe-logo', {
                            method: 'POST',
                            headers: this.headers(),
                            body: JSON.stringify({ image_url: img.url }),
                        });

                        if (response.ok) {
                            const data = await response.json();
                            if (data.prompt) {
                                this.logoPrompt = data.prompt;
                                this.$nextTick(() => {
                                    const textarea = this.$el.querySelector('#logo-prompt');
                                    if (textarea) {
                                        textarea.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                        textarea.classList.add('ring-2', 'ring-violet-400');
                                        setTimeout(() => textarea.classList.remove('ring-2', 'ring-violet-400'), 1200);
                                        textarea.focus();
                                    }
                                });
                            }
                        } else {
                            const err = await response.json().catch(() => ({}));
                            this.logoError = err.error || 'Failed to analyze image.';
                        }
                    } catch (e) {
                        this.logoError = 'Network error analyzing image.';
                    } finally {
                        this.setLogoImageState(idx, { describing: false });
                    }
                },

                queueSimilarIdeasLookup(promptText) {
                    if (this.similarIdeasDebounce) {
                        clearTimeout(this.similarIdeasDebounce);
                    }

                    const prompt = (promptText || '').trim();
                    if (prompt.length < 3) {
                        this.similarIdeas = [];
                        this.similarIdeasError = null;
                        this.similarIdeasLoading = false;
                        return;
                    }

                    this.similarIdeasDebounce = setTimeout(() => {
                        this.fetchSimilarIdeas(prompt);
                    }, 350);
                },

                async fetchSimilarIdeas(prompt) {
                    this.similarIdeasLoading = true;
                    this.similarIdeasError = null;

                    try {
                        const response = await fetch('/domain-search/logo-similar-ideas', {
                            method: 'POST',
                            headers: this.headers(),
                            body: JSON.stringify({
                                prompt,
                                limit: 6,
                            }),
                        });

                        const data = await response.json().catch(() => ({}));
                        if (!response.ok) {
                            this.similarIdeas = [];
                            this.similarIdeasError = data.error || 'Failed to load similar ideas.';
                            return;
                        }

                        this.similarIdeas = Array.isArray(data.ideas) ? data.ideas : [];
                    } catch (e) {
                        this.similarIdeas = [];
                        this.similarIdeasError = 'Network error while loading similar ideas.';
                    } finally {
                        this.similarIdeasLoading = false;
                    }
                },
            };
        }
    </script>
</body>
</html>
