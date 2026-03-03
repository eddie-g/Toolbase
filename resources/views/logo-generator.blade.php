<!DOCTYPE html>
<html lang="en">
<head>
    <script>if(localStorage.getItem('darkMode')==='true')document.documentElement.classList.add('dark');</script>
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
@php($logoUser = auth()->user() ?? auth('admin')->user())
<body class="bg-gray-50 dark:bg-gray-950 antialiased min-h-screen">

    <x-site-header />

    <main class="pt-28 pb-20" x-data="logoGenerator()">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8 max-w-[1200px]">
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

            <div class="flex gap-6 items-start">

            <div id="logo_main" class="flex-1 min-w-0 bg-white dark:bg-gray-900 rounded-2xl shadow-lg shadow-gray-200/50 dark:shadow-black/20 border border-gray-200 dark:border-gray-800 p-6 mb-8">
                @if ($logoUser)
                    {{-- AI Model selector --}}
                    <div class="mb-5">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Choose your model</label>
                        <div class="grid grid-cols-3 gap-2">
                            {{-- Fast (Flux) --}}
                            <button type="button" @click="logoImageModel = 'flux'; if (!['professional','fantasy','future','retro','greetingcard','custom'].includes(logoStyle)) { logoStyle = 'professional'; } logoIconOnly = false; fetchLogoPrice()"
                                class="flex flex-col gap-2 px-3 py-3 rounded-xl border-2 transition-all"
                                :class="logoImageModel === 'flux' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                <span class="text-sm font-bold w-full text-center" :class="logoImageModel === 'flux' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-700 dark:text-gray-300'">Fast</span>
                                <div class="w-full space-y-1.5">
                                    <div class="flex items-center justify-between text-xs leading-none" :class="logoImageModel === 'flux' ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500 dark:text-gray-400'">
                                        <span class="font-medium">Text</span>
                                        <span class="text-sm tracking-tight">★★★☆☆</span>
                                    </div>
                                    <div class="flex items-center justify-between text-xs leading-none" :class="logoImageModel === 'flux' ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500 dark:text-gray-400'">
                                        <span class="font-medium">Quality</span>
                                        <span class="text-sm tracking-tight">★★★☆☆</span>
                                    </div>
                                    <div class="flex items-center justify-between text-xs leading-none font-semibold" :class="logoImageModel === 'flux' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-300'">
                                        <span>Cost</span>
                                        <span x-text="formatCardPrice(modelCardPricePerImage.flux)"></span>
                                    </div>
                                </div>
                            </button>
                            {{-- Balanced (Recraft) --}}
                            <button type="button" @click="logoImageModel = 'recraft'; if (!['professional','fantasy','future','retro','greetingcard','custom'].includes(logoStyle)) { logoStyle = 'professional'; } logoIconOnly = false; fetchLogoPrice()"
                                class="flex flex-col gap-2 px-3 py-3 rounded-xl border-2 transition-all"
                                :class="logoImageModel === 'recraft' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                <span class="text-sm font-bold w-full text-center" :class="logoImageModel === 'recraft' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-700 dark:text-gray-300'">Balanced</span>
                                <div class="w-full space-y-1.5">
                                    <div class="flex items-center justify-between text-xs leading-none" :class="logoImageModel === 'recraft' ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500 dark:text-gray-400'">
                                        <span class="font-medium">Text</span>
                                        <span class="text-sm tracking-tight">★★★☆☆</span>
                                    </div>
                                    <div class="flex items-center justify-between text-xs leading-none" :class="logoImageModel === 'recraft' ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500 dark:text-gray-400'">
                                        <span class="font-medium">Quality</span>
                                        <span class="text-sm tracking-tight">★★★★☆</span>
                                    </div>
                                    <div class="flex items-center justify-between text-xs leading-none font-semibold" :class="logoImageModel === 'recraft' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-300'">
                                        <span>Cost</span>
                                        <span x-text="formatCardPrice(modelCardPricePerImage.recraft)"></span>
                                    </div>
                                </div>
                            </button>
                            {{-- Pro (DALL-E) --}}
                            <button type="button" @click="logoImageModel = 'dalle'; logoOutputFormat = 'raster'; if (!['professional','fantasy','future','retro','greetingcard','chrome','8bit','dotmatrix','custom'].includes(logoStyle)) { logoStyle = 'professional'; } if (!['chrome','dotmatrix'].includes(logoStyle)) { logoIconOnly = false; } fetchLogoPrice()"
                                class="flex flex-col gap-2 px-3 py-3 rounded-xl border-2 transition-all"
                                :class="logoImageModel === 'dalle' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                <span class="text-sm font-bold w-full text-center" :class="logoImageModel === 'dalle' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-700 dark:text-gray-300'">Pro</span>
                                <div class="w-full space-y-1.5">
                                    <div class="flex items-center justify-between text-xs leading-none" :class="logoImageModel === 'dalle' ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500 dark:text-gray-400'">
                                        <span class="font-medium">Text</span>
                                        <span class="text-sm tracking-tight">★★★★★</span>
                                    </div>
                                    <div class="flex items-center justify-between text-xs leading-none" :class="logoImageModel === 'dalle' ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500 dark:text-gray-400'">
                                        <span class="font-medium">Quality</span>
                                        <span class="text-sm tracking-tight">★★★★★</span>
                                    </div>
                                    <div class="flex items-center justify-between text-xs leading-none font-semibold" :class="logoImageModel === 'dalle' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-300'">
                                        <span>Cost</span>
                                        <span x-text="formatCardPrice(modelCardPricePerImage.dalle)"></span>
                                    </div>
                                </div>
                            </button>
                        </div>
                    </div>

                    <div class="mb-5" x-show="!logoIconOnly" x-transition>
                        <label for="logo-domain" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Text in Logo</label>
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
                        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">Enter the text you want included in your logo.</p>
                    </div>

                    <div class="mb-5">
                        <label for="logo-prompt" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            <span x-text="logoStyle === 'custom' ? 'Custom Prompt' : 'Describe Your Logo'"></span>
                            <span class="text-red-400 font-normal">*</span>
                            <span x-show="logoStyle === 'custom'" class="ml-1 px-1.5 py-0.5 text-[10px] font-semibold rounded bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-300 uppercase tracking-wide">Full Prompt</span>
                        </label>
                        <textarea
                            id="logo-prompt"
                            x-model="logoPrompt"
                            :rows="logoStyle === 'custom' ? 6 : 2"
                            :placeholder="logoStyle === 'custom' ? 'Write your full image prompt here. Palette and background settings below will be appended automatically.' : 'e.g. a rocket launching into space, a shield with a lion, abstract waves...'"
                            class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition text-sm resize-none"
                        ></textarea>
                        <p x-show="logoStyle !== 'custom'" class="mt-1.5 text-xs text-gray-400 dark:text-gray-500">Click <span class="font-semibold">Use as Prompt</span> on a generated logo to quickly iterate.</p>
                        <p x-show="logoStyle === 'custom'" class="mt-1.5 text-xs text-violet-500 dark:text-violet-400">Your prompt is sent directly to the AI. Use the palette &amp; background options to append color and background constraints.</p>
                    </div>

                    {{-- Output Format section --}}
                    <div class="mb-5" x-show="logoStyle !== 'custom'" x-transition>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Output Format</label>

                        {{-- Flux / Recraft: Vector vs Raster --}}
                        <div x-show="logoImageModel !== 'dalle'" class="flex gap-2">
                            <button type="button" @click="logoOutputFormat = 'raster'; fetchLogoPrice()"
                                class="flex-1 px-3 py-2.5 rounded-xl border-2 text-xs font-medium transition-all text-center"
                                :class="logoOutputFormat === 'raster' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-600'">
                                Raster
                            </button>
                            <button type="button" @click="logoOutputFormat = 'vector'; fetchLogoPrice()"
                                class="flex-1 px-3 py-2.5 rounded-xl border-2 text-xs font-medium transition-all text-center"
                                :class="logoOutputFormat === 'vector' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-600'">
                                Vector (SVG)
                            </button>
                        </div>
                        <p x-show="logoImageModel === 'recraft' && logoOutputFormat === 'vector'" x-transition class="mt-2 text-xs text-indigo-500 dark:text-indigo-400">Recraft generates native SVG vector logos directly — no rasterization needed.</p>
                        <p x-show="logoImageModel === 'flux' && logoOutputFormat === 'vector'" x-transition class="mt-2 text-xs text-indigo-500 dark:text-indigo-400">Flux raster output will be vectorized to SVG via post-processing.</p>

                        {{-- DALL-E 3: Image format only (no vector) --}}
                        <div x-show="logoImageModel === 'dalle'" class="flex gap-2">
                            <button type="button" @click="logoImageFormat = 'png'; fetchLogoPrice()"
                                class="flex-1 px-3 py-2.5 rounded-xl border-2 text-xs font-medium transition-all text-center"
                                :class="logoImageFormat === 'png' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-600'">
                                PNG
                            </button>
                            <button type="button" @click="logoImageFormat = 'bmp'; fetchLogoPrice()"
                                class="flex-1 px-3 py-2.5 rounded-xl border-2 text-xs font-medium transition-all text-center"
                                :class="logoImageFormat === 'bmp' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-600'">
                                BMP
                            </button>
                        </div>
                    </div>

                    {{-- Palette & BG triggers — mobile only (sidebar visible on lg+) --}}
                    <div class="mb-5 lg:hidden flex gap-2">
                        <button type="button" @click="showStylePanel = 'palette'"
                            class="flex-1 flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl border-2 transition-all text-left"
                            :class="logoColorPalette !== 'none' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                            <div class="flex h-5 w-14 rounded-md overflow-hidden flex-shrink-0 border border-gray-200 dark:border-gray-600">
                                <template x-for="(c, ci) in getSelectedPalettePreview()" :key="ci">
                                    <div class="flex-1" :style="'background-color: ' + c"></div>
                                </template>
                            </div>
                            <span class="text-xs font-medium text-gray-600 dark:text-gray-400" x-text="getSelectedPaletteName()"></span>
                            <svg class="w-3.5 h-3.5 ml-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                        <button type="button" @click="showStylePanel = 'background'"
                            class="flex items-center gap-2 px-3.5 py-2.5 rounded-xl border-2 transition-all text-left"
                            :class="logoBgColor !== 'white' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                            <div class="w-5 h-5 rounded border border-gray-200 dark:border-gray-600 flex-shrink-0" :style="'background-color: ' + (logoBgColor === 'custom' ? logoBgCustom : logoBgColor)"></div>
                            <span class="text-xs font-medium text-gray-600 dark:text-gray-400">BG</span>
                            <svg class="w-3.5 h-3.5 ml-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>

                    {{-- Slide-out Panel (Background + Palette on mobile) --}}
                    <template x-teleport="body">
                        <div x-show="showStylePanel" x-cloak class="fixed inset-0 z-50 flex justify-end" @keydown.escape.window="showStylePanel = null">
                            {{-- Backdrop --}}
                            <div x-show="showStylePanel" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                                class="absolute inset-0 bg-black/30 dark:bg-black/50" @click="showStylePanel = null"></div>
                            {{-- Panel --}}
                            <div x-show="showStylePanel" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
                                class="relative w-full max-w-sm bg-white dark:bg-gray-900 shadow-2xl border-l border-gray-200 dark:border-gray-800 flex flex-col h-full">
                                {{-- Panel Header --}}
                                <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200 dark:border-gray-800">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white" x-text="showStylePanel === 'palette' ? 'Color Palette' : 'Background'"></h3>
                                    <button @click="showStylePanel = null" class="p-1.5 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                                {{-- Panel Body --}}
                                <div class="flex-1 overflow-y-auto px-5 py-5">
                                    {{-- Color Palette (mobile slide-out) --}}
                                    <div x-show="showStylePanel === 'palette'">
                                        <div class="grid grid-cols-2 gap-2">
                                            {{-- None / AI picks --}}
                                            <button type="button" @click="logoColorPalette = 'none'"
                                                class="rounded-xl border-2 p-2 transition-all"
                                                :class="logoColorPalette === 'none' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                                <div class="flex h-6 rounded-md overflow-hidden items-center justify-center bg-gray-100 dark:bg-gray-700">
                                                    <span class="text-[10px] text-gray-400 dark:text-gray-400 font-medium">AI Picks</span>
                                                </div>
                                                <div class="mt-1.5 text-[11px] font-medium text-center" :class="logoColorPalette === 'none' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-500 dark:text-gray-400'">None</div>
                                            </button>
                                            <template x-for="p in colorPalettes" :key="p.id">
                                                <button type="button" @click="logoColorPalette = p.id"
                                                    class="rounded-xl border-2 p-2 transition-all"
                                                    :class="logoColorPalette === p.id ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                                    <div class="flex h-6 rounded-md overflow-hidden">
                                                        <template x-for="(c, ci) in p.colors" :key="ci">
                                                            <div class="flex-1" :style="'background-color: ' + c"></div>
                                                        </template>
                                                    </div>
                                                    <div class="mt-1.5 text-[11px] font-medium text-center truncate" :class="logoColorPalette === p.id ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-500 dark:text-gray-400'" x-text="p.name"></div>
                                                </button>
                                            </template>
                                        </div>
                                        {{-- Full-width Choose Custom button --}}
                                        <button type="button" @click="logoColorPalette = 'custom'"
                                            class="mt-2 w-full py-2.5 rounded-xl border-2 text-xs font-semibold transition-all flex items-center justify-center gap-2"
                                            :class="logoColorPalette === 'custom' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-600'">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                                            Choose Custom
                                        </button>
                                        <div x-show="logoColorPalette === 'custom'" x-transition class="mt-3 p-3 bg-gray-50 dark:bg-gray-800 rounded-xl">
                                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">Custom Colors</label>
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <template x-for="(c, ci) in logoCustomColors" :key="ci">
                                                    <div class="relative">
                                                        <div class="w-10 h-10 rounded-lg border-2 border-gray-300 dark:border-gray-600 cursor-pointer shadow-sm" :style="'background-color: ' + c"></div>
                                                    <input type="color" :value="normalizeHexColor(c)" @input="logoCustomColors[ci] = normalizeHexColor($event.target.value)" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" />
                                                    </div>
                                                </template>
                                                <button type="button" @click="logoCustomColors.length < 5 && logoCustomColors.push('#888888')" x-show="logoCustomColors.length < 5"
                                                    class="w-10 h-10 rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-600 flex items-center justify-center text-gray-400 hover:border-gray-400 hover:text-gray-500 transition">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                                                </button>
                                                <button type="button" @click="logoCustomColors.length > 2 && logoCustomColors.pop()" x-show="logoCustomColors.length > 2"
                                                    class="w-10 h-10 rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-600 flex items-center justify-center text-gray-400 hover:border-gray-400 hover:text-gray-500 transition">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path></svg>
                                                </button>
                                            </div>
                                            <div x-show="canManagePalettes" class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">
                                                <label class="block text-[11px] font-medium text-gray-600 dark:text-gray-400 mb-1.5">Save Palette</label>
                                                <div class="flex items-center gap-2">
                                                    <input type="text" x-model="savedPaletteName" maxlength="60" placeholder="Palette name"
                                                        class="flex-1 px-2.5 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-xs text-gray-700 dark:text-gray-200 placeholder-gray-400 focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition" />
                                                    <button type="button" @click="saveCurrentPalette()"
                                                        class="px-3 py-2 rounded-lg text-xs font-semibold transition"
                                                        :class="paletteSaving ? 'bg-gray-300 text-gray-500 cursor-not-allowed' : 'bg-emerald-600 hover:bg-emerald-700 text-white'"
                                                        :disabled="paletteSaving"
                                                        x-text="paletteSaving ? 'Saving...' : 'Save'"></button>
                                                </div>
                                                <p class="mt-1.5 text-[10px] text-red-500" x-show="paletteError" x-text="paletteError"></p>
                                                <p class="mt-1.5 text-[10px] text-emerald-600 dark:text-emerald-400" x-show="paletteSuccess" x-text="paletteSuccess"></p>

                                                <div class="mt-2.5 space-y-1.5">
                                                    <template x-for="palette in savedPalettes" :key="palette.id">
                                                        <div class="flex items-center gap-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-1.5">
                                                            <button type="button" @click="applySavedPalette(palette)" class="flex-1 min-w-0 flex items-center gap-2 text-left">
                                                                <div class="flex h-4 w-14 rounded overflow-hidden border border-gray-200 dark:border-gray-600">
                                                                    <template x-for="(c, ci) in palette.colors" :key="ci">
                                                                        <div class="flex-1" :style="'background-color: ' + c"></div>
                                                                    </template>
                                                                </div>
                                                                <span class="text-[11px] text-gray-700 dark:text-gray-300 truncate" x-text="palette.name"></span>
                                                            </button>
                                                            <button type="button" @click.stop="deleteSavedPalette(palette.id)"
                                                                class="p-1 rounded text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition"
                                                                title="Delete palette">
                                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7h6m-7 0V5a1 1 0 011-1h4a1 1 0 011 1v2"></path></svg>
                                                            </button>
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Shape Container (mobile, inside palette panel, Recraft/DALL-E) --}}
                                    <div x-show="showStylePanel === 'palette' && ['recraft','dalle'].includes(logoImageModel) && logoStyle !== 'custom'" x-transition class="mt-5 pt-4 border-t border-gray-200 dark:border-gray-700">
                                        <h4 class="text-xs font-semibold text-gray-600 dark:text-gray-400 mb-2">Shape Container</h4>
                                        <div class="grid grid-cols-3 gap-2">
                                            <template x-for="shape in ['none','circle','square','triangle','pentagon','hexagon','heart']" :key="shape">
                                                <button type="button" @click="logoShape = shape"
                                                    class="py-2 rounded-xl border-2 text-xs font-medium text-center capitalize transition-all"
                                                    :class="logoShape === shape ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300' : 'border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-600'"
                                                    x-text="shape"></button>
                                            </template>
                                        </div>
                                    </div>

                                    {{-- Detail Level (mobile, inside palette panel, Recraft + DALL-E) --}}
                                    <div x-show="showStylePanel === 'palette' && (logoImageModel === 'recraft' || logoImageModel === 'dalle') && logoStyle !== 'custom'" x-transition class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                                        <h4 class="text-xs font-semibold text-gray-600 dark:text-gray-400 mb-2">Detail Level</h4>
                                        <div class="grid grid-cols-3 gap-2">
                                            <template x-for="level in [{id:'min',label:'Min'},{id:'medium',label:'Medium'},{id:'max',label:'Max'}]" :key="level.id">
                                                <button type="button" @click="logoDetail = level.id"
                                                    class="py-2 rounded-xl border-2 text-xs font-medium text-center transition-all"
                                                    :class="logoDetail === level.id ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300' : 'border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-600'"
                                                    x-text="level.label"></button>
                                            </template>
                                        </div>
                                    </div>

                                    {{-- Background --}}
                                    <div x-show="showStylePanel === 'background'">
                                        <div class="space-y-3">
                                            <button type="button" @click="logoBgColor = 'white'; fetchLogoPrice()"
                                                class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border-2 transition-all"
                                                :class="logoBgColor === 'white' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                                <div class="w-8 h-8 rounded-lg border border-gray-200 dark:border-gray-600 bg-white flex-shrink-0"></div>
                                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">White</span>
                                                <svg x-show="logoBgColor === 'white'" class="w-4 h-4 ml-auto text-emerald-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                            </button>
                                            <button type="button" @click="logoBgColor = 'black'; fetchLogoPrice()"
                                                class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border-2 transition-all"
                                                :class="logoBgColor === 'black' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                                <div class="w-8 h-8 rounded-lg border border-gray-200 dark:border-gray-600 bg-black flex-shrink-0"></div>
                                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Black</span>
                                                <svg x-show="logoBgColor === 'black'" class="w-4 h-4 ml-auto text-emerald-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                            </button>
                                            <div class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border-2 transition-all"
                                                :class="logoBgColor === 'custom' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20' : 'border-gray-200 dark:border-gray-700'">
                                                <div class="relative flex-shrink-0">
                                                    <div class="w-8 h-8 rounded-lg border border-gray-200 dark:border-gray-600 cursor-pointer" :style="'background-color: ' + logoBgCustom"></div>
                                                    <input type="color" :value="normalizeHexColor(logoBgCustom)" @input="logoBgCustom = normalizeHexColor($event.target.value); logoBgColor = 'custom'; fetchLogoPrice()" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" />
                                                </div>
                                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Custom Color</span>
                                                <span class="text-xs text-gray-400 ml-auto font-mono" x-text="logoBgCustom"></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                {{-- Panel Footer --}}
                                <div class="px-5 py-4 border-t border-gray-200 dark:border-gray-800">
                                    <button @click="showStylePanel = null" class="w-full py-2.5 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold transition">Done</button>
                                </div>
                            </div>
                        </div>
                    </template>

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
                                <div class="text-sm font-semibold text-gray-900 dark:text-white" x-text="logoImageModel === 'dalle' ? 'HD Quality' : 'PRO Mode'"></div>
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider" :class="logoProMode ? 'bg-amber-500 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-500 dark:text-gray-400'" x-text="logoProMode ? 'ON' : 'OFF'"></span>
                            </div>
                            <div class="relative">
                                <div class="w-11 h-6 rounded-full transition-colors" :class="logoProMode ? 'bg-amber-500' : 'bg-gray-300 dark:bg-gray-600'"><div class="absolute top-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" :class="logoProMode ? 'translate-x-[22px]' : 'translate-x-0.5'"></div></div>
                            </div>
                        </div>
                        <div x-show="logoProMode && logoImageModel === 'flux'" x-transition class="mt-3">
                            <div class="grid grid-cols-3 gap-2">
                                <button type="button" @click="logoProSize = '512'; fetchLogoPrice()" class="py-2 rounded-lg border text-xs font-semibold transition" :class="logoProSize === '512' ? 'border-amber-500 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400'">512</button>
                                <button type="button" @click="logoProSize = '1024'; fetchLogoPrice()" class="py-2 rounded-lg border text-xs font-semibold transition" :class="logoProSize === '1024' ? 'border-amber-500 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400'">1024</button>
                                <button type="button" @click="logoProSize = '1536'; fetchLogoPrice()" class="py-2 rounded-lg border text-xs font-semibold transition" :class="logoProSize === '1536' ? 'border-amber-500 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400'">1536</button>
                            </div>
                        </div>
                    </div>

                    {{-- Insufficient balance warning --}}
                    <div x-show="!logoPriceLoading && creditBalance < logoCostTotal" x-transition
                        class="mb-3 flex items-center gap-2 px-3.5 py-2.5 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 text-red-700 dark:text-red-300 text-xs">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span>Insufficient balance (<strong x-text="'$' + creditBalance.toFixed(4)"></strong>). <a href="/admin/add-credits" class="underline font-semibold hover:text-red-900 dark:hover:text-red-100">Add credits</a></span>
                    </div>

                    <button type="button" @click="generateLogo()" :disabled="logoLoading || (logoStyle !== 'custom' && !logoIconOnly && !logoDomain.trim()) || !logoPrompt.trim() || (!logoPriceLoading && creditBalance < logoCostTotal)"
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
                        <span class="ml-1.5 px-2 py-0.5 rounded-md text-xs font-bold bg-white/20" x-text="logoPriceLoading ? '...' : '$' + logoCostTotal.toFixed(4)"></span>
                    </button>

                    <div x-show="logoImages.length > 0" class="mt-6">
                        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Generated Logos</h3>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <template x-for="(img, idx) in logoImages" :key="idx">
                                <div class="rounded-xl overflow-hidden border border-gray-200 dark:border-gray-700 shadow-sm">
                                    <div class="aspect-square cursor-zoom-in"
                                        @click="zoomLogo(idx)"
                                        :style="img.transparent
                                            ? 'background: repeating-conic-gradient(#d1d5db 0% 25%, #f3f4f6 0% 50%) 50% / 20px 20px'
                                            : logoBgResult.startsWith('#')
                                                ? 'background-color: ' + logoBgResult
                                                : logoBgResult === 'black'
                                                    ? 'background-color: #000'
                                                    : 'background-color: #fff'">
                                        <img :src="img.stored_url || img.url" :alt="'Logo ' + (idx+1)" class="w-full h-full object-cover" />
                                    </div>
                                    <div class="p-2 flex gap-1.5 flex-wrap">
                                        <a :href="img.stored_url || img.url" :download="logoDomain + '-logo-' + (idx+1) + '.png'" target="_blank" class="flex-1 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition text-center">Save</a>
                                        <a x-show="img.svg_url" :href="img.svg_url" :download="logoDomain + '-logo-' + (idx+1) + '.svg'" target="_blank" class="flex-1 py-1.5 rounded-lg bg-emerald-100 dark:bg-emerald-900/30 text-xs font-medium text-emerald-700 dark:text-emerald-300 hover:bg-emerald-200 dark:hover:bg-emerald-800/40 transition text-center">SVG</a>
                                        @if(config('services.logo_editor_enabled'))
                                        <a :href="logoEditorUrl(idx, img)"
                                           class="flex-1 py-1.5 rounded-lg bg-green-600 text-xs font-medium text-white hover:bg-green-700 transition text-center"
                                           :class="{ 'opacity-50 pointer-events-none': !logoRequestId }">
                                           Open in Editor
                                        </a>
                                        @endif
                                        <button x-show="img.seed" type="button" @click.stop.prevent="useSeed(img.seed)"
                                            class="w-full py-1.5 rounded-lg bg-amber-100 dark:bg-amber-900/30 text-xs font-medium text-amber-700 dark:text-amber-300 hover:bg-amber-200 dark:hover:bg-amber-800/40 transition flex items-center justify-center gap-1 cursor-pointer">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                            </svg>
                                            <span x-text="'Use Seed ' + img.seed"></span>
                                        </button>
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
                                        <button type="button" @click.stop.prevent="removeBackground(idx)" :disabled="img.removingBg"
                                            x-show="!(logoImageModel !== 'dalle' && logoOutputFormat === 'vector')"
                                            class="w-full py-1.5 rounded-lg text-xs font-medium transition flex items-center justify-center gap-1 cursor-pointer relative z-10 disabled:cursor-not-allowed"
                                            :class="img.removingBg
                                                ? 'bg-rose-200 dark:bg-rose-900/50 text-rose-400'
                                                : 'bg-rose-100 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300 hover:bg-rose-200 dark:hover:bg-rose-800/40'">
                                            <svg x-show="img.removingBg" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                            </svg>
                                            <svg x-show="!img.removingBg" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                            </svg>
                                            <span x-text="img.removingBg ? 'Removing…' : 'Remove Background'"></span>
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Other Ideas: similar saved logos based on the current prompt --}}
                    <div x-show="(logoPrompt.trim().length >= 3 || similarIdeasLoading || similarIdeasError || similarIdeas.length > 0) && logoImages.length > 0" class="mt-6">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Other Ideas</h3>
                            <span class="text-xs text-gray-400 dark:text-gray-500" x-show="similarIdeas.length > 0" x-text="similarIdeas.length + ' similar saved ideas'"></span>
                        </div>

                        <div x-show="similarIdeasLoading" class="text-xs text-gray-500 dark:text-gray-400 mb-2">Looking for similar saved icon ideas...</div>

                        <div x-show="similarIdeasError" class="mb-2 p-2 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 text-xs text-red-700 dark:text-red-300" x-text="similarIdeasError"></div>

                        <div x-show="!similarIdeasLoading && !similarIdeasError && logoPrompt.trim().length >= 3 && similarIdeas.length === 0" class="text-xs text-gray-500 dark:text-gray-400">No similar saved icon ideas found yet.</div>

                        <div x-show="similarIdeas.length > 0" class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <template x-for="idea in similarIdeas" :key="idea.id">
                                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 overflow-hidden">
                                    <div class="aspect-square bg-white dark:bg-gray-900 flex items-center justify-center p-2">
                                        <img :src="idea.image_urls[0]" alt="Similar logo idea" class="w-full h-full object-contain" loading="lazy" />
                                    </div>
                                    <div class="p-2">
                                        <p class="text-xs text-gray-700 dark:text-gray-300 leading-relaxed line-clamp-2" x-text="idea.prompt"></p>
                                        <div class="mt-2 flex items-center justify-between gap-2">
                                            <span class="text-[11px] text-gray-400 dark:text-gray-500" x-text="Math.round((idea.score || 0) * 100) + '%'"></span>
                                            <div class="flex items-center gap-1.5">
                                                @if(config('services.logo_editor_enabled'))
                                                <a :href="'/logos/' + idea.id + '/edit?image=0'" class="px-2 py-1 rounded-lg bg-green-600 text-white text-[11px] font-medium hover:bg-green-700 transition">Editor</a>
                                                @endif
                                                <button type="button" class="px-2 py-1 rounded-lg bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-300 text-[11px] font-medium hover:bg-violet-200 dark:hover:bg-violet-800/40 transition" @click="logoPrompt = idea.prompt">Use</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Style Selection Modal --}}
                    <div x-show="showStyleModal" x-cloak x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4" @click.self="showStyleModal = false" @keydown.escape.window="showStyleModal = false">
                        <div class="relative w-full max-w-lg bg-white dark:bg-gray-900 rounded-2xl shadow-2xl overflow-hidden" @click.stop
                            x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100">
                            {{-- Header --}}
                            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200 dark:border-gray-700">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Choose Style</h3>
                                <button @click="showStyleModal = false" class="p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                                    <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </button>
                            </div>
                            {{-- Style Grid --}}
                            <div class="p-5">
                                {{-- DALL-E styles --}}
                                <div x-show="logoImageModel === 'dalle'">
                                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                        <button type="button" @click="selectStyle('professional')"
                                            class="group rounded-xl border-2 p-3 transition-all text-center"
                                            :class="logoStyle === 'professional' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                                <svg class="w-6 h-6" :class="logoStyle === 'professional' ? 'text-emerald-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                                            </div>
                                            <div class="text-xs font-semibold"
                                                :class="logoStyle === 'professional' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Professional</div>
                                            <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Clean & modern</div>
                                        </button>
                                        <button type="button" @click="selectStyle('fantasy')"
                                            class="group rounded-xl border-2 p-3 transition-all text-center"
                                            :class="logoStyle === 'fantasy' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                                <svg class="w-6 h-6" :class="logoStyle === 'fantasy' ? 'text-emerald-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"></path></svg>
                                            </div>
                                            <div class="text-xs font-semibold"
                                                :class="logoStyle === 'fantasy' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Fantasy</div>
                                            <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Magical & ornate</div>
                                        </button>
                                        <button type="button" @click="selectStyle('future')"
                                            class="group rounded-xl border-2 p-3 transition-all text-center"
                                            :class="logoStyle === 'future' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                                <svg class="w-6 h-6" :class="logoStyle === 'future' ? 'text-emerald-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"></path></svg>
                                            </div>
                                            <div class="text-xs font-semibold"
                                                :class="logoStyle === 'future' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Future</div>
                                            <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Techy & sci-fi</div>
                                        </button>
                                        <button type="button" @click="selectStyle('retro')"
                                            class="group rounded-xl border-2 p-3 transition-all text-center"
                                            :class="logoStyle === 'retro' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                                <svg class="w-6 h-6" :class="logoStyle === 'retro' ? 'text-emerald-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                            </div>
                                            <div class="text-xs font-semibold"
                                                :class="logoStyle === 'retro' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Retro</div>
                                            <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Vintage & classic</div>
                                        </button>
                                        <button type="button" @click="selectStyle('minimalist')"
                                            class="group rounded-xl border-2 p-3 transition-all text-center"
                                            :class="logoStyle === 'minimalist' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                                <svg class="w-6 h-6" :class="logoStyle === 'minimalist' ? 'text-emerald-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                            </div>
                                            <div class="text-xs font-semibold"
                                                :class="logoStyle === 'minimalist' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Minimalist</div>
                                            <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Simple & clean</div>
                                        </button>
                                        <button type="button" @click="selectStyle('greetingcard')"
                                            class="group rounded-xl border-2 p-3 transition-all text-center"
                                            :class="logoStyle === 'greetingcard' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                                <svg class="w-6 h-6" :class="logoStyle === 'greetingcard' ? 'text-emerald-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"></path></svg>
                                            </div>
                                            <div class="text-xs font-semibold"
                                                :class="logoStyle === 'greetingcard' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Greeting Card</div>
                                            <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Watercolor & gouache</div>
                                        </button>
                                        <button type="button" @click="selectStyle('photorealistic')"
                                            class="group rounded-xl border-2 p-3 transition-all text-center"
                                            :class="logoStyle === 'photorealistic' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                                <svg class="w-6 h-6" :class="logoStyle === 'photorealistic' ? 'text-emerald-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z"></path></svg>
                                            </div>
                                            <div class="text-xs font-semibold"
                                                :class="logoStyle === 'photorealistic' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Photorealistic</div>
                                            <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Lifelike & detailed</div>
                                        </button>
                                        @if(config('services.logo_custom_prompt_enabled'))
                                        <button type="button" @click="selectStyle('custom')"
                                            class="col-span-2 sm:col-span-3 group rounded-xl border-2 p-3 transition-all text-center"
                                            :class="logoStyle === 'custom' ? 'border-violet-500 ring-2 ring-violet-200 dark:ring-violet-800 bg-violet-50 dark:bg-violet-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                                <svg class="w-6 h-6" :class="logoStyle === 'custom' ? 'text-violet-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"></path></svg>
                                            </div>
                                            <div class="text-xs font-semibold" :class="logoStyle === 'custom' ? 'text-violet-700 dark:text-violet-300' : 'text-gray-600 dark:text-gray-400'">Custom Prompt</div>
                                            <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Write your own full prompt</div>
                                        </button>
                                        @endif
                                    </div>
                                    <div class="mt-5 mb-4">
                                        <div class="w-full h-px bg-gradient-to-r from-transparent via-emerald-400/70 to-transparent"></div>
                                        <div class="text-center -mt-3">
                                            <span class="inline-flex px-3 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider bg-white dark:bg-gray-900 text-emerald-600 dark:text-emerald-400">Dalle3 specific styles</span>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                        <button type="button" @click="selectStyle('chrome')" x-show="!logoIconOnly"
                                            class="group rounded-xl border-2 p-2 transition-all text-center"
                                            :class="logoStyle === 'chrome' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                            <div class="aspect-square rounded-lg overflow-hidden bg-gray-100 dark:bg-gray-800 mb-2">
                                                <img src="/images/chrome-preview.svg" alt="Chrome style" class="w-full h-full object-cover" />
                                            </div>
                                            <div class="text-xs font-semibold"
                                                :class="logoStyle === 'chrome' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Chrome (Chome)</div>
                                            <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">3D metallic render</div>
                                            <span class="inline-block mt-1 px-1.5 py-0.5 rounded text-[9px] font-semibold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">Icon Only</span>
                                        </button>
                                        <button type="button" @click="selectStyle('dotmatrix')" x-show="!logoIconOnly"
                                            class="group rounded-xl border-2 p-3 transition-all text-center"
                                            :class="logoStyle === 'dotmatrix' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                                <svg class="w-6 h-6" :class="logoStyle === 'dotmatrix' ? 'text-emerald-600' : 'text-gray-400'" fill="currentColor" viewBox="0 0 24 24"><circle cx="6" cy="6" r="1.5"/><circle cx="12" cy="6" r="1.5"/><circle cx="18" cy="6" r="1.5"/><circle cx="6" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="18" cy="12" r="1.5"/><circle cx="6" cy="18" r="1.5"/><circle cx="12" cy="18" r="1.5"/><circle cx="18" cy="18" r="1.5"/></svg>
                                            </div>
                                            <div class="text-xs font-semibold"
                                                :class="logoStyle === 'dotmatrix' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Dot Matrix</div>
                                            <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Stipple art</div>
                                            <span class="inline-block mt-1 px-1.5 py-0.5 rounded text-[9px] font-semibold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">Icon Only</span>
                                        </button>
                                        <button type="button" @click="selectStyle('8bit')" x-show="!logoIconOnly"
                                            class="group rounded-xl border-2 p-3 transition-all text-center"
                                            :class="logoStyle === '8bit' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                                <svg class="w-6 h-6" :class="logoStyle === '8bit' ? 'text-emerald-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"></path></svg>
                                            </div>
                                            <div class="text-xs font-semibold"
                                                :class="logoStyle === '8bit' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">8-Bit</div>
                                            <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Fantasy RPG</div>
                                        </button>
                                    </div>
                                </div>

                                {{-- Flux/Recraft styles --}}
                                <div x-show="logoImageModel !== 'dalle'" class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                    <button type="button" @click="selectStyle('professional')"
                                        class="group rounded-xl border-2 p-3 transition-all text-center"
                                        :class="logoStyle === 'professional' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                        <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                            <svg class="w-6 h-6" :class="logoStyle === 'professional' ? 'text-emerald-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                                        </div>
                                        <div class="text-xs font-semibold"
                                            :class="logoStyle === 'professional' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Professional</div>
                                        <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Clean & modern</div>
                                    </button>
                                    <button type="button" @click="selectStyle('fantasy')"
                                        class="group rounded-xl border-2 p-3 transition-all text-center"
                                        :class="logoStyle === 'fantasy' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                        <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                            <svg class="w-6 h-6" :class="logoStyle === 'fantasy' ? 'text-emerald-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"></path></svg>
                                        </div>
                                        <div class="text-xs font-semibold"
                                            :class="logoStyle === 'fantasy' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Fantasy</div>
                                        <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Magical & ornate</div>
                                    </button>
                                    <button type="button" @click="selectStyle('future')"
                                        class="group rounded-xl border-2 p-3 transition-all text-center"
                                        :class="logoStyle === 'future' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                        <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                            <svg class="w-6 h-6" :class="logoStyle === 'future' ? 'text-emerald-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"></path></svg>
                                        </div>
                                        <div class="text-xs font-semibold"
                                            :class="logoStyle === 'future' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Future</div>
                                        <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Techy & sci-fi</div>
                                    </button>
                                    <button type="button" @click="selectStyle('retro')"
                                        class="group rounded-xl border-2 p-3 transition-all text-center"
                                        :class="logoStyle === 'retro' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                        <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                            <svg class="w-6 h-6" :class="logoStyle === 'retro' ? 'text-emerald-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                        </div>
                                        <div class="text-xs font-semibold"
                                            :class="logoStyle === 'retro' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Retro</div>
                                        <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Vintage & classic</div>
                                    </button>
                                    <button type="button" @click="selectStyle('minimalist')"
                                        class="group rounded-xl border-2 p-3 transition-all text-center"
                                        :class="logoStyle === 'minimalist' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                        <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                            <svg class="w-6 h-6" :class="logoStyle === 'minimalist' ? 'text-emerald-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                        </div>
                                        <div class="text-xs font-semibold"
                                            :class="logoStyle === 'minimalist' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Minimalist</div>
                                        <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Simple & clean</div>
                                    </button>
                                    <button type="button" @click="selectStyle('greetingcard')"
                                        class="group rounded-xl border-2 p-3 transition-all text-center"
                                        :class="logoStyle === 'greetingcard' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                        <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                            <svg class="w-6 h-6" :class="logoStyle === 'greetingcard' ? 'text-emerald-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"></path></svg>
                                        </div>
                                        <div class="text-xs font-semibold"
                                            :class="logoStyle === 'greetingcard' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Greeting Card</div>
                                        <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Watercolor & gouache</div>
                                    </button>
                                    <button type="button" @click="selectStyle('photorealistic')"
                                        class="group rounded-xl border-2 p-3 transition-all text-center"
                                        :class="logoStyle === 'photorealistic' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                        <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                            <svg class="w-6 h-6" :class="logoStyle === 'photorealistic' ? 'text-emerald-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z"></path></svg>
                                        </div>
                                        <div class="text-xs font-semibold"
                                            :class="logoStyle === 'photorealistic' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Photorealistic</div>
                                        <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Lifelike & detailed</div>
                                    </button>
                                    @if(config('services.logo_custom_prompt_enabled'))
                                        class="group rounded-xl border-2 p-3 transition-all text-center"
                                        :class="logoStyle === 'greetingcard' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                        <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                            <svg class="w-6 h-6" :class="logoStyle === 'greetingcard' ? 'text-emerald-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"></path></svg>
                                        </div>
                                        <div class="text-xs font-semibold"
                                            :class="logoStyle === 'greetingcard' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Greeting Card</div>
                                        <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Watercolor & gouache</div>
                                    </button>
                                    @if(config('services.logo_custom_prompt_enabled'))
                                    <button type="button" @click="selectStyle('custom')"
                                        class="col-span-2 sm:col-span-3 group rounded-xl border-2 p-3 transition-all text-center"
                                        :class="logoStyle === 'custom' ? 'border-violet-500 ring-2 ring-violet-200 dark:ring-violet-800 bg-violet-50 dark:bg-violet-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                        <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                            <svg class="w-6 h-6" :class="logoStyle === 'custom' ? 'text-violet-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"></path></svg>
                                        </div>
                                        <div class="text-xs font-semibold" :class="logoStyle === 'custom' ? 'text-violet-700 dark:text-violet-300' : 'text-gray-600 dark:text-gray-400'">Custom Prompt</div>
                                        <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Write your own full prompt</div>
                                    </button>
                                    @endif
                                </div>
                            </div>
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
                @endif
            </div>

            {{-- Palette Sidebar (right of form) --}}
            @if ($logoUser)
            <div id="logo_sidebar" class="hidden lg:block w-56 flex-shrink-0 sticky top-28" x-transition>
                {{-- Balance --}}
                <div class="mb-3 bg-white dark:bg-gray-900 rounded-2xl shadow-lg shadow-gray-200/50 dark:shadow-black/20 border border-gray-200 dark:border-gray-800 px-4 py-3 flex items-center justify-between">
                    <span class="text-[11px] font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Balance</span>
                    <span class="text-sm font-bold" :class="creditBalance < 0.01 ? 'text-red-500' : 'text-emerald-600 dark:text-emerald-400'" x-text="'$' + creditBalance.toFixed(4)"></span>
                </div>

                {{-- Text in Logo toggle --}}
                <div class="mb-3 bg-white dark:bg-gray-900 rounded-2xl shadow-lg shadow-gray-200/50 dark:shadow-black/20 border border-gray-200 dark:border-gray-800 px-4 py-3">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider" x-text="!logoIconOnly ? 'Text in Logo' : 'Icon Only'"></span>
                        <button type="button" @click="if ((logoStyle === 'chrome' || logoStyle === 'dotmatrix') && logoIconOnly) { $dispatch('chrome-text-error-desktop'); return; } logoIconOnly = !logoIconOnly"
                            class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900"
                            :class="!logoIconOnly ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600'"
                            role="switch" :aria-checked="(!logoIconOnly).toString()">
                            <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition-transform duration-200 ease-in-out"
                                :class="!logoIconOnly ? 'translate-x-5' : 'translate-x-0'"></span>
                        </button>
                    </div>
                    <div x-show="false"
                        @chrome-text-error-desktop.window="$el.style.display = 'flex'; setTimeout(() => $el.style.display = 'none', 3000)"
                        class="items-center gap-1.5 mt-2 px-2.5 py-1.5 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-400 text-xs font-medium" style="display: none;">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                        Text in logo is not available for this style (icon-only)
                    </div>
                </div>

                {{-- Style Trigger (opens modal) --}}
                <button type="button" @click="showStyleModal = true"
                    class="mb-3 w-full bg-white dark:bg-gray-900 rounded-2xl shadow-lg shadow-gray-200/50 dark:shadow-black/20 border border-gray-200 dark:border-gray-800 p-3 flex items-center gap-3 hover:border-gray-300 dark:hover:border-gray-600 transition-all group text-left">
                    {{-- Tiny preview --}}
                    <div class="w-10 h-10 rounded-lg overflow-hidden flex-shrink-0 bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                        <template x-if="logoStyle === 'chrome'">
                            <img src="/images/chrome-preview.svg" alt="Chrome" class="w-full h-full object-cover" />
                        </template>
                        <template x-if="logoStyle !== 'chrome'">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42"></path></svg>
                        </template>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-[10px] font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wider">Style</div>
                        <div class="text-sm font-semibold text-gray-900 dark:text-white truncate" x-text="getStyleLabel()"></div>
                    </div>
                    <svg class="w-4 h-4 text-gray-400 group-hover:text-gray-500 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </button>

                <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-lg shadow-gray-200/50 dark:shadow-black/20 border border-gray-200 dark:border-gray-800 p-4">
                    <h3 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Palette</h3>
                    {{-- None option --}}
                    <button type="button" @click="logoColorPalette = 'none'"
                        class="w-full mb-2 rounded-xl border-2 p-1.5 transition-all flex items-center gap-2"
                        :class="logoColorPalette === 'none' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                        <div class="flex-1 h-5 rounded-md bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                            <span class="text-[10px] font-medium text-gray-400">AI Picks</span>
                        </div>
                        <div class="text-[10px] font-medium" :class="logoColorPalette === 'none' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-500 dark:text-gray-400'">None</div>
                    </button>
                    <div class="grid grid-cols-2 gap-2">
                        <template x-for="p in colorPalettes" :key="p.id">
                            <button type="button" @click="logoColorPalette = p.id"
                                class="rounded-xl border-2 p-1.5 transition-all"
                                :class="logoColorPalette === p.id ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                <div class="flex h-5 rounded-md overflow-hidden">
                                    <template x-for="(c, ci) in p.colors" :key="ci">
                                        <div class="flex-1" :style="'background-color: ' + c"></div>
                                    </template>
                                </div>
                                <div class="mt-1 text-[10px] font-medium text-center truncate" :class="logoColorPalette === p.id ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-500 dark:text-gray-400'" x-text="p.name"></div>
                            </button>
                        </template>
                    </div>
                    {{-- Full-width Choose Custom button --}}
                    <button type="button" @click="logoColorPalette = 'custom'"
                        class="mt-2 w-full py-2 rounded-xl border-2 text-[11px] font-semibold transition-all flex items-center justify-center gap-1.5"
                        :class="logoColorPalette === 'custom' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300' : 'border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-600'">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                        Choose Custom
                    </button>
                    {{-- Custom palette color pickers --}}
                    <div x-show="logoColorPalette === 'custom'" x-transition class="mt-2 p-2.5 bg-gray-50 dark:bg-gray-800 rounded-xl">
                        <label class="block text-[10px] font-medium text-gray-500 dark:text-gray-400 mb-2">Custom Colors</label>
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <template x-for="(c, ci) in logoCustomColors" :key="ci">
                                <div class="relative">
                                    <div class="w-8 h-8 rounded-lg border-2 border-gray-300 dark:border-gray-600 cursor-pointer shadow-sm" :style="'background-color: ' + c"></div>
                                    <input type="color" :value="normalizeHexColor(c)" @input="logoCustomColors[ci] = normalizeHexColor($event.target.value)" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" />
                                </div>
                            </template>
                            <button type="button" @click="logoCustomColors.length < 5 && logoCustomColors.push('#888888')" x-show="logoCustomColors.length < 5"
                                class="w-8 h-8 rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-600 flex items-center justify-center text-gray-400 hover:border-gray-400 hover:text-gray-500 transition">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                            </button>
                            <button type="button" @click="logoCustomColors.length > 2 && logoCustomColors.pop()" x-show="logoCustomColors.length > 2"
                                class="w-8 h-8 rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-600 flex items-center justify-center text-gray-400 hover:border-gray-400 hover:text-gray-500 transition">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path></svg>
                            </button>
                        </div>
                        <div x-show="canManagePalettes" class="mt-2.5 pt-2.5 border-t border-gray-200 dark:border-gray-700">
                            <label class="block text-[10px] font-medium text-gray-500 dark:text-gray-400 mb-1.5">Save Palette</label>
                            <div class="flex items-center gap-1.5">
                                <input type="text" x-model="savedPaletteName" maxlength="60" placeholder="Palette name"
                                    class="flex-1 px-2.5 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-[11px] text-gray-700 dark:text-gray-200 placeholder-gray-400 focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition" />
                                <button type="button" @click="saveCurrentPalette()"
                                    class="px-2.5 py-1.5 rounded-lg text-[10px] font-semibold transition"
                                    :class="paletteSaving ? 'bg-gray-300 text-gray-500 cursor-not-allowed' : 'bg-emerald-600 hover:bg-emerald-700 text-white'"
                                    :disabled="paletteSaving"
                                    x-text="paletteSaving ? 'Saving...' : 'Save'"></button>
                            </div>
                            <p class="mt-1 text-[10px] text-red-500" x-show="paletteError" x-text="paletteError"></p>
                            <p class="mt-1 text-[10px] text-emerald-600 dark:text-emerald-400" x-show="paletteSuccess" x-text="paletteSuccess"></p>

                            <div class="mt-2 space-y-1">
                                <template x-for="palette in savedPalettes" :key="palette.id">
                                    <div class="flex items-center gap-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-1">
                                        <button type="button" @click="applySavedPalette(palette)" class="flex-1 min-w-0 flex items-center gap-1.5 text-left">
                                            <div class="flex h-3.5 w-12 rounded overflow-hidden border border-gray-200 dark:border-gray-600">
                                                <template x-for="(c, ci) in palette.colors" :key="ci">
                                                    <div class="flex-1" :style="'background-color: ' + c"></div>
                                                </template>
                                            </div>
                                            <span class="text-[10px] text-gray-700 dark:text-gray-300 truncate" x-text="palette.name"></span>
                                        </button>
                                        <button type="button" @click.stop="deleteSavedPalette(palette.id)"
                                            class="p-1 rounded text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition"
                                            title="Delete palette">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7h6m-7 0V5a1 1 0 011-1h4a1 1 0 011 1v2"></path></svg>
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                    {{-- Shape Container Section (Recraft/DALL-E) --}}
                    <div x-show="['recraft','dalle'].includes(logoImageModel) && logoStyle !== 'custom'" x-transition class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <h3 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Shape Container</h3>
                        <div class="grid grid-cols-3 gap-1.5">
                            <template x-for="shape in ['none','circle','square','triangle','pentagon','hexagon','heart']" :key="shape">
                                <button type="button" @click="logoShape = shape"
                                    class="py-1.5 px-1 rounded-lg border-2 text-[10px] font-medium text-center capitalize transition-all"
                                    :class="logoShape === shape ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300' : 'border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-600'"
                                    x-text="shape"></button>
                            </template>
                        </div>
                    </div>

                    {{-- Detail Level Section (Recraft + DALL-E) --}}
                    <div x-show="(logoImageModel === 'recraft' || logoImageModel === 'dalle') && logoStyle !== 'custom'" x-transition class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <h3 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Detail Level</h3>
                        <div class="grid grid-cols-3 gap-1.5">
                            <template x-for="level in [{id:'min',label:'Min'},{id:'medium',label:'Medium'},{id:'max',label:'Max'}]" :key="level.id">
                                <button type="button" @click="logoDetail = level.id"
                                    class="py-1.5 px-1 rounded-lg border-2 text-[10px] font-medium text-center transition-all"
                                    :class="logoDetail === level.id ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300' : 'border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-600'"
                                    x-text="level.label"></button>
                            </template>
                        </div>
                    </div>

                    {{-- Background Section --}}
                    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <h3 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Background</h3>
                        <div class="space-y-1.5">
                            <button type="button" @click="logoBgColor = 'white'; fetchLogoPrice()"
                                class="w-full flex items-center gap-2 px-2.5 py-2 rounded-lg border transition-all text-left"
                                :class="logoBgColor === 'white' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                <div class="w-5 h-5 rounded border border-gray-200 dark:border-gray-600 bg-white flex-shrink-0"></div>
                                <span class="text-[11px] font-medium text-gray-600 dark:text-gray-400">White</span>
                                <svg x-show="logoBgColor === 'white'" class="w-3.5 h-3.5 ml-auto text-emerald-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            </button>
                            <button type="button" @click="logoBgColor = 'black'; fetchLogoPrice()"
                                class="w-full flex items-center gap-2 px-2.5 py-2 rounded-lg border transition-all text-left"
                                :class="logoBgColor === 'black' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                <div class="w-5 h-5 rounded border border-gray-200 dark:border-gray-600 bg-black flex-shrink-0"></div>
                                <span class="text-[11px] font-medium text-gray-600 dark:text-gray-400">Black</span>
                                <svg x-show="logoBgColor === 'black'" class="w-3.5 h-3.5 ml-auto text-emerald-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            </button>
                            <div class="w-full flex items-center gap-2 px-2.5 py-2 rounded-lg border transition-all"
                                :class="logoBgColor === 'custom' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20' : 'border-gray-200 dark:border-gray-700'">
                                <div class="relative flex-shrink-0">
                                    <div class="w-5 h-5 rounded border border-gray-200 dark:border-gray-600 cursor-pointer" :style="'background-color: ' + logoBgCustom"></div>
                                    <input type="color" :value="normalizeHexColor(logoBgCustom)" @input="logoBgCustom = normalizeHexColor($event.target.value); logoBgColor = 'custom'; fetchLogoPrice()" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" />
                                </div>
                                <span class="text-[11px] font-medium text-gray-600 dark:text-gray-400">Custom</span>
                                <span class="text-[10px] text-gray-400 ml-auto font-mono" x-text="logoBgCustom"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @endif

            </div>{{-- /flex --}}
        </div>
    </main>

    <script>
        const registerLogoGenerator = () => {
            Alpine.data('logoGenerator', () => ({
                logoDomain: '',
                logoPrompt: '',
                logoStyle: 'professional',
                logoCount: 4,
                logoCostPerImage: 0.003,
                logoCostTotal: 0.012,
                logoCostSource: 'loading',
                logoPriceLoading: false,
                modelCardPricePerImage: {
                    flux: null,
                    recraft: null,
                    dalle: null,
                },
                canManagePalettes: @js((bool) $logoUser),
                savedPalettes: [],
                savedPaletteName: '',
                paletteSaving: false,
                paletteLoading: false,
                paletteError: null,
                paletteSuccess: null,
                logoLoading: false,
                logoImages: [],
                logoError: null,
                zoomedLogoUrl: null,
                creditBalance: @js((float) ($logoUser->credit_balance ?? 0)),
                logoProMode: false,
                logoProSize: '1024',
                logoIconOnly: false,
                logoImageModel: 'dalle',
                logoOutputFormat: 'raster',
                logoImageFormat: 'png',
                logoBgColor: 'white',
                logoBgCustom: '#ffffff',
                logoBgResult: 'white',
                logoColorPalette: 'none',
                logoCustomColors: ['#1e3a5f', '#d4af37', '#333333'],
                recraftSubstyle: null,
                logoShape: 'none',
                logoDetail: 'max',
                showStylePanel: null,
                showStyleModal: false,
                colorPalettes: [
                    { id: 'fire',   name: 'Fire',   colors: ['#D00000', '#E85D04', '#FFBA08'] },
                    { id: 'pastel', name: 'Pastel', colors: ['#FFB5A7', '#FCD5CE', '#A2D2FF'] },
                    { id: 'royal',  name: 'Royal',  colors: ['#7B2CBF', '#C77DFF', '#E0AAFF'] },
                    { id: 'ice',    name: 'Ice',    colors: ['#0077B6', '#00B4D8', '#90E0EF'] },
                ],
                logoRequestId: null,
                logoSeedNumber: null,
                similarIdeas: [],
                similarIdeasLoading: false,
                similarIdeasError: null,
                similarIdeasDebounce: null,

                normalizeHexColor(value, fallback = '#ffffff') {
                    const v = String(value || '').trim();
                    return /^#[0-9a-fA-F]{6}$/.test(v) ? v : fallback;
                },

                init() {
                    const queryParams = new URLSearchParams(window.location.search || '');
                    const initialDomain = (queryParams.get('domain') || '').trim();
                    if (initialDomain) {
                        this.logoDomain = initialDomain;
                    }

                    const initialStyle = (queryParams.get('style') || '').trim();
                    const validStyles = ['chrome','professional','fantasy','future','retro','8bit','dotmatrix','lego','minimalist','greetingcard','custom'];
                    if (validStyles.includes(initialStyle)) {
                        this.logoStyle = initialStyle;
                    } else {
                        const stripped = initialStyle.replace(/_pro$/, '');
                        if (validStyles.includes(stripped)) this.logoStyle = stripped;
                    }

                    const initialModel = (queryParams.get('model') || '').trim();
                    if (['flux','recraft','dalle'].includes(initialModel)) this.logoImageModel = initialModel;

                    const initialShape = (queryParams.get('shape') || '').trim();
                    if (initialShape) this.logoShape = initialShape;

                    const initialDetail = (queryParams.get('detail') || '').trim();
                    if (['min','medium','max'].includes(initialDetail)) this.logoDetail = initialDetail;

                    const initialBg = (queryParams.get('bg') || '').trim();
                    if (initialBg) {
                        if (initialBg.startsWith('#')) {
                            this.logoBgColor = 'custom';
                            this.logoBgCustom = this.normalizeHexColor(initialBg);
                        } else if (['white','black','transparent'].includes(initialBg)) {
                            this.logoBgColor = initialBg;
                        }
                    }

                    if (queryParams.get('icon_only') === '1') this.logoIconOnly = true;

                    this.logoBgCustom = this.normalizeHexColor(this.logoBgCustom);
                    this.logoCustomColors = (Array.isArray(this.logoCustomColors) ? this.logoCustomColors : [])
                        .map((c) => this.normalizeHexColor(c))
                        .slice(0, 5);
                    if (this.logoCustomColors.length < 2) {
                        this.logoCustomColors = ['#1e3a5f', '#d4af37', '#333333'];
                    }
                    this.fetchLogoPrice();
                    this.fetchModelCardPrices();
                    this.fetchSavedPalettes();
                    this.$watch('logoPrompt', (val) => this.queueSimilarIdeasLookup(val));
                },

                formatCardPrice(value) {
                    const amount = Number(value);
                    if (!Number.isFinite(amount) || amount <= 0) return '...';
                    const digits = amount < 0.01 ? 4 : 3;
                    return '$' + amount.toFixed(digits).replace(/0+$/, '').replace(/\.$/, '');
                },

                normalizePaletteColors(colors) {
                    if (!Array.isArray(colors)) return [];
                    return colors
                        .map((color) => this.normalizeHexColor(color, ''))
                        .filter((color) => /^#[0-9a-fA-F]{6}$/.test(color))
                        .map((color) => color.toUpperCase())
                        .slice(0, 5);
                },

                async fetchSavedPalettes() {
                    if (!this.canManagePalettes) return;
                    this.paletteLoading = true;
                    this.paletteError = null;
                    try {
                        const response = await fetch('/domain-search/logo-palettes', {
                            method: 'GET',
                            headers: this.headers(),
                        });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok) {
                            this.paletteError = data.error || 'Failed to load saved palettes.';
                            return;
                        }
                        const incoming = Array.isArray(data.palettes) ? data.palettes : [];
                        this.savedPalettes = incoming
                            .map((p) => ({
                                id: p.id,
                                name: String(p.name || '').trim(),
                                colors: this.normalizePaletteColors(p.colors),
                            }))
                            .filter((p) => p.id && p.name && p.colors.length >= 2);
                    } catch (e) {
                        this.paletteError = 'Network error loading saved palettes.';
                    } finally {
                        this.paletteLoading = false;
                    }
                },

                async saveCurrentPalette() {
                    if (!this.canManagePalettes || this.paletteSaving) return;
                    const name = String(this.savedPaletteName || '').trim();
                    if (!name) {
                        this.paletteError = 'Enter a palette name.';
                        this.paletteSuccess = null;
                        return;
                    }
                    const colors = this.normalizePaletteColors(this.logoCustomColors);
                    if (colors.length < 2) {
                        this.paletteError = 'Palette needs at least 2 colors.';
                        this.paletteSuccess = null;
                        return;
                    }

                    this.paletteSaving = true;
                    this.paletteError = null;
                    this.paletteSuccess = null;

                    try {
                        const response = await fetch('/domain-search/logo-palettes', {
                            method: 'POST',
                            headers: this.headers(),
                            body: JSON.stringify({ name, colors }),
                        });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok) {
                            this.paletteError = data.error || 'Failed to save palette.';
                            return;
                        }
                        await this.fetchSavedPalettes();
                        this.paletteSuccess = 'Palette saved.';
                    } catch (e) {
                        this.paletteError = 'Network error saving palette.';
                    } finally {
                        this.paletteSaving = false;
                    }
                },

                applySavedPalette(palette) {
                    const colors = this.normalizePaletteColors(palette?.colors || []);
                    if (colors.length < 2) return;
                    this.logoCustomColors = colors;
                    this.logoColorPalette = 'custom';
                    this.savedPaletteName = String(palette?.name || '').trim();
                    this.paletteError = null;
                    this.paletteSuccess = `Loaded "${this.savedPaletteName}".`;
                },

                async deleteSavedPalette(paletteId) {
                    if (!this.canManagePalettes || !paletteId) return;
                    this.paletteError = null;
                    this.paletteSuccess = null;
                    try {
                        const response = await fetch('/domain-search/logo-palettes/' + encodeURIComponent(paletteId), {
                            method: 'DELETE',
                            headers: this.headers(),
                        });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok) {
                            this.paletteError = data.error || 'Failed to delete palette.';
                            return;
                        }
                        this.savedPalettes = this.savedPalettes.filter((p) => Number(p.id) !== Number(paletteId));
                        this.paletteSuccess = 'Palette deleted.';
                    } catch (e) {
                        this.paletteError = 'Network error deleting palette.';
                    }
                },

                getStyleLabel() {
                    const labels = { chrome: 'Chrome', professional: 'Professional', fantasy: 'Fantasy', future: 'Future', retro: 'Retro', '8bit': '8-Bit', dotmatrix: 'Dot Matrix', lego: 'Lego', minimalist: 'Minimalist', greetingcard: 'Greeting Card', custom: 'Custom Prompt' };
                    return labels[this.logoStyle] || 'Professional';
                },
                selectStyle(style) {
                    this.logoStyle = style;
                    if (style === 'chrome' || style === 'dotmatrix') {
                        this.logoIconOnly = true;
                    } else if (this.logoIconOnly && this.logoImageModel === 'dalle') {
                        // Switching away from icon-only styles on DALL-E, restore text
                        this.logoIconOnly = false;
                    }
                    this.showStyleModal = false;
                    this.fetchLogoPrice();
                },

                getSelectedPalettePreview() {
                    if (this.logoColorPalette === 'none') return ['#e5e7eb', '#e5e7eb', '#e5e7eb'];
                    if (this.logoColorPalette === 'custom') return this.logoCustomColors.slice(0, 3);
                    const p = this.colorPalettes.find(p => p.id === this.logoColorPalette);
                    return p ? p.colors : ['#e5e7eb', '#e5e7eb', '#e5e7eb'];
                },
                getSelectedPaletteName() {
                    if (this.logoColorPalette === 'none') return 'AI Picks';
                    if (this.logoColorPalette === 'custom') return 'Custom';
                    const p = this.colorPalettes.find(p => p.id === this.logoColorPalette);
                    return p ? p.name : 'AI Picks';
                },

                getSelectedPaletteColors() {
                    if (this.logoColorPalette === 'none') return null;
                    if (this.logoColorPalette === 'custom') return this.logoCustomColors;
                    const p = this.colorPalettes.find(p => p.id === this.logoColorPalette);
                    return p ? p.colors : null;
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
                        const bgColor = this.logoBgColor === 'custom' ? this.normalizeHexColor(this.logoBgCustom) : this.logoBgColor;
                        const response = await fetch('/domain-search/estimate-logo-price', {
                            method: 'POST',
                            headers: this.headers(),
                            body: JSON.stringify({
                                count: this.logoCount,
                                pro: this.logoProMode,
                                pro_size: this.logoProMode ? parseInt(this.logoProSize) : 512,
                                style: this.logoStyle,
                                bg_color: bgColor,
                                image_model: this.logoImageModel,
                                output_format: this.logoImageModel === 'dalle' ? 'raster' : this.logoOutputFormat,
                                image_format: this.logoImageModel === 'dalle' ? this.logoImageFormat : null,
                                recraft_substyle: this.logoImageModel === 'recraft' ? (this.recraftSubstyle || null) : null,
                            }),
                        });
                        if (response.ok) {
                            const data = await response.json();
                            this.logoCostPerImage = parseFloat(data.cost_per_image) || 0.003;
                            this.logoCostTotal = parseFloat(data.estimated_cost_usd) || (this.logoCount * 0.003);
                            this.logoCostSource = data.source || 'fallback';
                            if (data.credit_balance !== undefined) {
                                this.creditBalance = parseFloat(data.credit_balance);
                            }
                        }
                    } catch (e) {
                    } finally {
                        this.logoPriceLoading = false;
                    }
                },

                async fetchModelCardPrices() {
                    const basePayload = {
                        count: 1,
                        pro: false,
                        pro_size: 512,
                        style: 'professional',
                        bg_color: 'white',
                        output_format: 'raster',
                        image_format: null,
                        recraft_substyle: null,
                    };

                    const requests = [
                        { key: 'flux', payload: { ...basePayload, image_model: 'flux' } },
                        { key: 'recraft', payload: { ...basePayload, image_model: 'recraft' } },
                        { key: 'dalle', payload: { ...basePayload, image_model: 'dalle', image_format: 'png' } },
                    ];

                    await Promise.all(requests.map(async ({ key, payload }) => {
                        try {
                            const response = await fetch('/domain-search/estimate-logo-price', {
                                method: 'POST',
                                headers: this.headers(),
                                body: JSON.stringify(payload),
                            });
                            if (!response.ok) return;
                            const data = await response.json();
                            const cost = parseFloat(data.cost_per_image);
                            if (Number.isFinite(cost) && cost > 0) {
                                this.modelCardPricePerImage[key] = cost;
                            }
                        } catch (e) {
                        }
                    }));
                },

                async generateLogo() {
                    const domain = this.logoDomain.trim();
                    if (!domain && !this.logoIconOnly) return;
                    if (!this.logoPrompt.trim()) return;

                    const totalCount = this.logoCount;
                    this.logoLoading = true;
                    this.logoError = null;
                    this.logoImages = [];
                    this.logoRequestId = null;
                    this.logoSeedNumber = null;
                    this.zoomedLogoUrl = null;

                    // Step 1: Dispatch all jobs (they return immediately with job IDs)
                    const pendingJobs = [];
                    for (let i = 0; i < totalCount; i++) {
                        try {
                            const response = await fetch('/domain-search/generate-logo', {
                                method: 'POST',
                                headers: this.headers(),
                                body: JSON.stringify({
                                    domain: domain,
                                    style: this.logoStyle,
                                    count: 1,
                                    total_count: totalCount,
                                    batch_index: i,
                                    custom_prompt: this.logoPrompt.trim() || null,
                                    pro: this.logoProMode,
                                    pro_size: this.logoProMode ? parseInt(this.logoProSize) : null,
                                    icon_only: this.logoIconOnly,
                                    bg_color: this.logoBgColor === 'custom' ? this.normalizeHexColor(this.logoBgCustom) : this.logoBgColor,
                                    image_model: this.logoImageModel,
                                    output_format: this.logoImageModel === 'dalle' ? 'raster' : this.logoOutputFormat,
                                    image_format: this.logoImageModel === 'dalle' ? this.logoImageFormat : null,
                                    color_palette: this.logoColorPalette !== 'none' ? this.getSelectedPaletteColors() : null,
                                    recraft_substyle: this.logoImageModel === 'recraft' ? (this.recraftSubstyle || null) : null,
                                    logo_shape: ['recraft','dalle'].includes(this.logoImageModel) ? this.logoShape : null,
                                    logo_detail: ['recraft', 'dalle'].includes(this.logoImageModel) ? this.logoDetail : null,
                                }),
                            });

                            if (response.status === 419) {
                                this.logoError = 'Session expired. Please refresh the page and try again.';
                                break;
                            }

                            const data = await response.json();

                            if (!response.ok) {
                                this.logoError = data.error || 'Failed to queue logo ' + (i + 1) + '.';
                                if (data.credit_balance !== undefined) {
                                    this.creditBalance = parseFloat(data.credit_balance);
                                }
                                if (response.status === 402) break;
                                continue;
                            }

                            if (data.logo_request_id) {
                                pendingJobs.push(data.logo_request_id);
                            }

                            if (!this.logoRequestId) {
                                this.logoRequestId = data.logo_request_id || null;
                            }

                            if (data.credit_balance !== undefined) {
                                this.creditBalance = parseFloat(data.credit_balance);
                            }
                        } catch (e) {
                            this.logoError = 'Network error queuing logo ' + (i + 1) + '.';
                        }
                    }

                    if (pendingJobs.length === 0) {
                        if (!this.logoError) {
                            this.logoError = 'No logos were queued. Please try again.';
                        }
                        this.logoLoading = false;
                        return;
                    }

                    // Step 2: Poll for completion with adaptive interval.
                    // Interval backs off while all jobs are still pending (avoids hammering),
                    // but resets to a short value whenever any job resolves so remaining
                    // jobs are detected quickly (they usually finish within seconds of each other).
                    const completedJobs = new Set();
                    const failedJobs = new Set();
                    const maxPollTime = 5 * 60 * 1000; // 5 min timeout
                    const pollStart = Date.now();
                    const minPollInterval = 3000; // floor: 3 s
                    const maxPollInterval = 8000; // ceiling: 8 s — reset to min on any completion
                    let pollInterval = minPollInterval;

                    while (completedJobs.size + failedJobs.size < pendingJobs.length) {
                        if (Date.now() - pollStart > maxPollTime) {
                            this.logoError = 'Logo generation timed out. Some images may still be processing.';
                            break;
                        }

                        await new Promise(resolve => setTimeout(resolve, pollInterval));

                        let anyResolved = false;
                        for (const jobId of pendingJobs) {
                            if (completedJobs.has(jobId) || failedJobs.has(jobId)) continue;

                            try {
                                const statusRes = await fetch('/domain-search/logo-status/' + jobId, {
                                    headers: this.headers(),
                                });
                                const statusData = await statusRes.json();

                                if (statusData.status === 'completed') {
                                    completedJobs.add(jobId);
                                    anyResolved = true;

                                    if (statusData.seed) {
                                        this.logoSeedNumber = statusData.seed;
                                    }

                                    if (statusData.bg_color) {
                                        this.logoBgResult = statusData.bg_color;
                                        if (typeof statusData.bg_color === 'string' && statusData.bg_color.startsWith('#')) {
                                            this.logoBgCustom = this.normalizeHexColor(statusData.bg_color);
                                        }
                                    }

                                    const newImages = (statusData.images || []).map((img) => ({
                                        ...img,
                                        seed: statusData.seed || null,
                                        describing: false,
                                        removingBg: false,
                                    }));
                                    this.logoImages = [...this.logoImages, ...newImages];

                                    if (statusData.credit_balance !== undefined) {
                                        this.creditBalance = parseFloat(statusData.credit_balance);
                                    }
                                } else if (statusData.status === 'failed' || statusData.status === 'error') {
                                    failedJobs.add(jobId);
                                    anyResolved = true;
                                    this.logoError = statusData.error || 'Logo generation failed.';

                                    if (statusData.credit_balance !== undefined) {
                                        this.creditBalance = parseFloat(statusData.credit_balance);
                                    }
                                }
                                // 'pending' or 'processing' — keep polling
                            } catch (e) {
                                // Network error during poll — will retry next iteration
                                console.warn('[logo-poll] fetch error for job', jobId, e);
                            }
                        }

                        // A job just finished — siblings are likely nearly done too, so
                        // poll quickly. Otherwise back off gradually to ease server load.
                        if (anyResolved) {
                            pollInterval = minPollInterval;
                        } else {
                            pollInterval = Math.min(Math.round(pollInterval * 1.5), maxPollInterval);
                        }
                    }

                    if (this.logoImages.length === 0 && !this.logoError) {
                        this.logoError = 'No logos were generated. Please try again.';
                    }
                    this.logoLoading = false;
                },

                zoomLogo(idx) {
                    if (idx === undefined || !this.logoImages[idx]) return;
                    const zi = this.logoImages[idx];
                    this.zoomedLogoUrl = zi.stored_url || zi.url;
                },

                useSeed(seed) {
                    this.logoSeedNumber = seed;
                    // Prepend seed reference to prompt if not already there
                    const seedPrefix = 'Using seed ' + seed + ', ';
                    if (!this.logoPrompt.startsWith('Using seed ')) {
                        this.logoPrompt = seedPrefix + this.logoPrompt;
                    } else {
                        // Replace existing seed prefix
                        this.logoPrompt = this.logoPrompt.replace(/^Using seed \d+, /, seedPrefix);
                    }
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
                            body: JSON.stringify({ image_url: img.stored_url || img.url }),
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

                async removeBackground(idx) {
                    const img = this.logoImages[idx];
                    if (!img || img.removingBg) return;

                    this.setLogoImageState(idx, { removingBg: true });

                    try {
                        const response = await fetch('/domain-search/remove-logo-bg', {
                            method: 'POST',
                            headers: this.headers(),
                            body: JSON.stringify({ image_url: img.stored_url || img.url }),
                        });

                        if (response.ok) {
                            const data = await response.json();
                            if (data.transparent_url) {
                                this.setLogoImageState(idx, {
                                    url: data.transparent_url,
                                    stored_url: data.transparent_url,
                                    transparent: true,
                                    removingBg: false,
                                });
                            }
                        } else {
                            const err = await response.json().catch(() => ({}));
                            this.logoError = err.error || 'Failed to remove background.';
                            if (err.credit_balance !== undefined) {
                                this.creditBalance = parseFloat(err.credit_balance);
                            }
                        }
                    } catch (e) {
                        this.logoError = 'Network error removing background.';
                    } finally {
                        this.setLogoImageState(idx, { removingBg: false });
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
            }));
        };

        if (window.Alpine) {
            registerLogoGenerator();
        } else {
            document.addEventListener('alpine:init', registerLogoGenerator, { once: true });
        }
    </script>
</body>
</html>
