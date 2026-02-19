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

            <div class="flex-1 min-w-0 bg-white dark:bg-gray-900 rounded-2xl shadow-lg shadow-gray-200/50 dark:shadow-black/20 border border-gray-200 dark:border-gray-800 p-6 mb-8">
                @auth
                    {{-- Text in Logo toggle --}}
                    <div class="flex items-center justify-between mb-5">
                        <span class="text-base font-semibold text-gray-800 dark:text-gray-200">Text in Logo</span>
                        <div class="flex items-center gap-4">
                            {{-- Image Model Switch --}}
                            <div class="flex items-center bg-gray-100 dark:bg-gray-800 rounded-lg p-0.5">
                                <button type="button" @click="logoImageModel = 'flux'; if (logoStyle === 'chrome') { logoStyle = 'professional'; logoIconOnly = false; } fetchLogoPrice()"
                                    class="px-3 py-1.5 rounded-md text-xs font-semibold transition-all"
                                    :class="logoImageModel === 'flux'
                                        ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm'
                                        : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'">
                                    Flux
                                </button>
                                <button type="button" @click="logoImageModel = 'dalle'; logoOutputFormat = 'raster'; if (!['chrome','retro','8bit','dotmatrix','lego'].includes(logoStyle)) { logoStyle = 'chrome'; logoIconOnly = true; } fetchLogoPrice()"
                                    class="px-3 py-1.5 rounded-md text-xs font-semibold transition-all"
                                    :class="logoImageModel === 'dalle'
                                        ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm'
                                        : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'">
                                    DALL-E 3
                                </button>
                                <button type="button" @click="logoImageModel = 'recraft'; if (logoStyle === 'chrome') { logoStyle = 'professional'; logoIconOnly = false; } fetchLogoPrice()"
                                    class="px-3 py-1.5 rounded-md text-xs font-semibold transition-all"
                                    :class="logoImageModel === 'recraft'
                                        ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm'
                                        : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'">
                                    Recraft
                                </button>
                            </div>
                            <button type="button" @click="if ((logoStyle === 'chrome' || logoStyle === 'dotmatrix') && logoIconOnly) { $dispatch('chrome-text-error'); return; } logoIconOnly = !logoIconOnly"
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
                            <span class="text-xs font-medium ml-1" :class="!logoIconOnly ? 'text-gray-700 dark:text-gray-300' : 'text-gray-400 dark:text-gray-500'" x-text="!logoIconOnly ? 'Text in Logo' : 'Icon Only'"></span>
                        </div>
                        {{-- Chrome icon-only restriction notice --}}
                        <div x-show="false" x-ref="chromeTextError"
                            @chrome-text-error.window="$el.style.display = 'flex'; setTimeout(() => $el.style.display = 'none', 3000)"
                            class="items-center gap-1.5 mt-1 px-2.5 py-1.5 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-400 text-xs font-medium" style="display: none;">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                            Text in logo is not available for this style (icon-only)
                        </div>
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
                        <label for="logo-prompt" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Describe Your Logo <span class="text-red-400 font-normal">*</span></label>
                        <textarea
                            id="logo-prompt"
                            x-model="logoPrompt"
                            rows="2"
                            placeholder="e.g. a rocket launching into space, a shield with a lion, abstract waves..."
                            class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition text-sm resize-none"
                        ></textarea>
                        <p class="mt-1.5 text-xs text-gray-400 dark:text-gray-500">Click <span class="font-semibold">Use as Prompt</span> on a generated logo to quickly iterate.</p>
                    </div>

                    {{-- Output Format section --}}
                    <div class="mb-5">
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
                            :class="logoColorPalette !== 'default' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
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
                                        <div class="grid grid-cols-3 gap-2">
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
                                        <div x-show="logoColorPalette === 'custom'" x-transition class="mt-4 p-3 bg-gray-50 dark:bg-gray-800 rounded-xl">
                                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">Custom Colors</label>
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <template x-for="(c, ci) in logoCustomColors" :key="ci">
                                                    <div class="relative">
                                                        <div class="w-10 h-10 rounded-lg border-2 border-gray-300 dark:border-gray-600 cursor-pointer shadow-sm" :style="'background-color: ' + c"></div>
                                                        <input type="color" :value="c" @input="logoCustomColors[ci] = $event.target.value" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" />
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
                                        </div>
                                    </div>

                                    {{-- Shape Container (mobile, inside palette panel, Recraft only) --}}
                                    <div x-show="showStylePanel === 'palette' && logoImageModel === 'recraft'" x-transition class="mt-5 pt-4 border-t border-gray-200 dark:border-gray-700">
                                        <h4 class="text-xs font-semibold text-gray-600 dark:text-gray-400 mb-2">Shape Container</h4>
                                        <div class="grid grid-cols-3 gap-2">
                                            <template x-for="shape in ['none','circle','square','triangle','pentagon','hexagon']" :key="shape">
                                                <button type="button" @click="logoShape = shape"
                                                    class="py-2 rounded-xl border-2 text-xs font-medium text-center capitalize transition-all"
                                                    :class="logoShape === shape ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300' : 'border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-600'"
                                                    x-text="shape"></button>
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
                                                    <input type="color" x-model="logoBgCustom" @input="logoBgColor = 'custom'; fetchLogoPrice()" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" />
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

                    {{-- Pricing Breakdown (50% markup) --}}
                    <div x-show="!logoPriceLoading && logoBaseCost > 0" x-transition
                        class="mb-3 px-3.5 py-2.5 rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 text-blue-700 dark:text-blue-300 text-xs">
                        <div class="flex items-center gap-2 mb-1">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                            <span class="font-semibold">Pricing Breakdown</span>
                            <span class="ml-auto text-[10px] opacity-75" x-text="'(' + logoCount + ' image' + (logoCount > 1 ? 's' : '') + ')'"></span>
                        </div>
                        <div class="ml-5.5 space-y-0.5 text-[11px]">
                            <div class="flex justify-between opacity-70">
                                <span>Per Image:</span>
                                <span class="font-mono" x-text="'$' + logoCostPerImage.toFixed(4)"></span>
                            </div>
                            <div class="flex justify-between">
                                <span>Base API Cost:</span>
                                <span class="font-mono" x-text="'$' + logoBaseCost.toFixed(4)"></span>
                            </div>
                            <div class="flex justify-between">
                                <span>Markup (50%):</span>
                                <span class="font-mono" x-text="'+ $' + logoMarkup.toFixed(4)"></span>
                            </div>
                            <div class="flex justify-between pt-1 border-t border-blue-300 dark:border-blue-700 font-semibold">
                                <span>Total Cost:</span>
                                <span class="font-mono" x-text="'$' + logoCostTotal.toFixed(4)"></span>
                            </div>
                        </div>
                    </div>

                    <button type="button" @click="generateLogo()" :disabled="logoLoading || (!logoIconOnly && !logoDomain.trim()) || !logoPrompt.trim() || (!logoPriceLoading && creditBalance < logoCostTotal)"
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
                                <div x-show="logoImageModel === 'dalle'" class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                    <button type="button" @click="selectStyle('chrome')"
                                        class="group rounded-xl border-2 p-2 transition-all text-center"
                                        :class="logoStyle === 'chrome' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                        <div class="aspect-square rounded-lg overflow-hidden bg-gray-100 dark:bg-gray-800 mb-2">
                                            <img src="/images/chrome-preview.svg" alt="Chrome style" class="w-full h-full object-cover" />
                                        </div>
                                        <div class="text-xs font-semibold"
                                            :class="logoStyle === 'chrome' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Chrome</div>
                                        <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">3D metallic render</div>
                                        <span class="inline-block mt-1 px-1.5 py-0.5 rounded text-[9px] font-semibold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">Icon Only</span>
                                    </button>
                                    <button type="button" @click="selectStyle('retro')"
                                        class="group rounded-xl border-2 p-3 transition-all text-center"
                                        :class="logoStyle === 'retro' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                        <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                            <svg class="w-6 h-6" :class="logoStyle === 'retro' ? 'text-emerald-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                        </div>
                                        <div class="text-xs font-semibold"
                                            :class="logoStyle === 'retro' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Retro</div>
                                        <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Vibrant sunburst</div>
                                    </button>
                                    <button type="button" @click="selectStyle('8bit')"
                                        class="group rounded-xl border-2 p-3 transition-all text-center"
                                        :class="logoStyle === '8bit' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                        <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                            <svg class="w-6 h-6" :class="logoStyle === '8bit' ? 'text-emerald-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"></path></svg>
                                        </div>
                                        <div class="text-xs font-semibold"
                                            :class="logoStyle === '8bit' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">8-Bit</div>
                                        <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Fantasy RPG</div>
                                    </button>
                                    <button type="button" @click="selectStyle('dotmatrix')"
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
                                    <button type="button" @click="selectStyle('lego')"
                                        class="group rounded-xl border-2 p-3 transition-all text-center"
                                        :class="logoStyle === 'lego' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                        <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                            <svg class="w-6 h-6" :class="logoStyle === 'lego' ? 'text-emerald-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.182 15.182a4.5 4.5 0 01-6.364 0M21 12a9 9 0 11-18 0 9 9 0 0118 0zM9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75 9.168 9 9.375 9s.375.336.375.75zm-.375 0h.008v.015h-.008V9.75zm5.625 0c0 .414-.168.75-.375.75s-.375-.336-.375-.75.168-.75.375-.75.375.336.375.75zm-.375 0h.008v.015h-.008V9.75z"></path></svg>
                                        </div>
                                        <div class="text-xs font-semibold"
                                            :class="logoStyle === 'lego' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Lego</div>
                                        <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Glossy sticker</div>
                                    </button>
                                    <button type="button" @click="selectStyle('minimalist')"
                                        class="group rounded-xl border-2 p-3 transition-all text-center"
                                        :class="logoStyle === 'minimalist' ? 'border-emerald-500 ring-2 ring-emerald-200 dark:ring-emerald-800 bg-emerald-50 dark:bg-emerald-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                                        <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                                            <svg class="w-6 h-6" :class="logoStyle === 'minimalist' ? 'text-emerald-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path></svg>
                                        </div>
                                        <div class="text-xs font-semibold"
                                            :class="logoStyle === 'minimalist' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Minimalist</div>
                                        <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">Flat design</div>
                                    </button>
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
                @endauth
            </div>

            {{-- Palette Sidebar (right of form) --}}
            <div class="hidden lg:block w-56 flex-shrink-0 sticky top-28" x-transition>
                {{-- Balance --}}
                <div class="mb-3 bg-white dark:bg-gray-900 rounded-2xl shadow-lg shadow-gray-200/50 dark:shadow-black/20 border border-gray-200 dark:border-gray-800 px-4 py-3 flex items-center justify-between">
                    <span class="text-[11px] font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Balance</span>
                    <span class="text-sm font-bold" :class="creditBalance < 0.01 ? 'text-red-500' : 'text-emerald-600 dark:text-emerald-400'" x-text="'$' + creditBalance.toFixed(4)"></span>
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
                    {{-- Custom palette color pickers --}}
                    <div x-show="logoColorPalette === 'custom'" x-transition class="mt-3 p-2.5 bg-gray-50 dark:bg-gray-800 rounded-xl">
                        <label class="block text-[10px] font-medium text-gray-500 dark:text-gray-400 mb-2">Custom Colors</label>
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <template x-for="(c, ci) in logoCustomColors" :key="ci">
                                <div class="relative">
                                    <div class="w-8 h-8 rounded-lg border-2 border-gray-300 dark:border-gray-600 cursor-pointer shadow-sm" :style="'background-color: ' + c"></div>
                                    <input type="color" :value="c" @input="logoCustomColors[ci] = $event.target.value" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" />
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
                    </div>
                </div>

                    {{-- Shape Container Section (Recraft only) --}}
                    <div x-show="logoImageModel === 'recraft'" x-transition class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <h3 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Shape Container</h3>
                        <div class="grid grid-cols-3 gap-1.5">
                            <template x-for="shape in ['none','circle','square','triangle','pentagon','hexagon']" :key="shape">
                                <button type="button" @click="logoShape = shape"
                                    class="py-1.5 px-1 rounded-lg border-2 text-[10px] font-medium text-center capitalize transition-all"
                                    :class="logoShape === shape ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300' : 'border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-600'"
                                    x-text="shape"></button>
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
                                    <input type="color" x-model="logoBgCustom" @input="logoBgColor = 'custom'; fetchLogoPrice()" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" />
                                </div>
                                <span class="text-[11px] font-medium text-gray-600 dark:text-gray-400">Custom</span>
                                <span class="text-[10px] text-gray-400 ml-auto font-mono" x-text="logoBgCustom"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            </div>{{-- /flex --}}
        </div>
    </main>

    <script>
        function logoGenerator() {
            return {
                logoDomain: '',
                logoPrompt: '',
                logoStyle: 'chrome',
                logoCount: 4,
                logoCostPerImage: 0.003,
                logoCostTotal: 0.012,
                logoCostSource: 'loading',
                logoBaseCost: 0,
                logoMarkup: 0,
                logoPriceLoading: false,
                logoLoading: false,
                logoImages: [],
                logoError: null,
                zoomedLogoUrl: null,
                creditBalance: {{ auth()->user() ? auth()->user()->credit_balance : 0 }},
                logoProMode: false,
                logoProSize: '1024',
                logoIconOnly: false,
                logoImageModel: 'flux',
                logoOutputFormat: 'raster',
                logoImageFormat: 'png',
                logoBgColor: 'white',
                logoBgCustom: '#ffffff',
                logoBgResult: 'white',
                logoColorPalette: 'default',
                logoCustomColors: ['#1e3a5f', '#d4af37', '#333333'],
                recraftSubstyle: null,
                logoShape: 'none',
                showStylePanel: null,
                showStyleModal: false,
                colorPalettes: [
                    { id: 'default', name: 'Default', colors: ['#1B2A4A', '#C9A84C', '#2C2C2C'] },
                    { id: 'ocean', name: 'Ocean', colors: ['#0077B6', '#00B4D8', '#90E0EF'] },
                    { id: 'sunset', name: 'Sunset', colors: ['#FF6B35', '#F7C59F', '#1A535C'] },
                    { id: 'forest', name: 'Forest', colors: ['#2D6A4F', '#52B788', '#D8F3DC'] },
                    { id: 'royal', name: 'Royal', colors: ['#7B2CBF', '#C77DFF', '#E0AAFF'] },
                    { id: 'fire', name: 'Fire', colors: ['#D00000', '#E85D04', '#FFBA08'] },
                    { id: 'mono', name: 'Monochrome', colors: ['#212529', '#6C757D', '#DEE2E6'] },
                    { id: 'pastel', name: 'Pastel', colors: ['#FFB5A7', '#FCD5CE', '#A2D2FF'] },
                    { id: 'neon', name: 'Neon', colors: ['#39FF14', '#FF073A', '#00F0FF'] },
                    { id: 'custom', name: 'Custom', colors: ['#1e3a5f', '#d4af37', '#888888'] },
                ],
                logoRequestId: null,
                logoSeedNumber: null,
                similarIdeas: [],
                similarIdeasLoading: false,
                similarIdeasError: null,
                similarIdeasDebounce: null,

                init() {
                    this.fetchLogoPrice();
                    this.$watch('logoPrompt', (val) => this.queueSimilarIdeasLookup(val));
                },

                getStyleLabel() {
                    const labels = { chrome: 'Chrome', professional: 'Professional', fantasy: 'Fantasy', future: 'Future', retro: 'Retro', '8bit': '8-Bit', dotmatrix: 'Dot Matrix', lego: 'Lego', minimalist: 'Minimalist' };
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
                    const p = this.colorPalettes.find(p => p.id === this.logoColorPalette);
                    if (this.logoColorPalette === 'custom') return this.logoCustomColors.slice(0, 3);
                    return p ? p.colors : ['#1B2A4A', '#C9A84C', '#2C2C2C'];
                },
                getSelectedPaletteName() {
                    const p = this.colorPalettes.find(p => p.id === this.logoColorPalette);
                    return p ? p.name : 'Default';
                },

                getSelectedPaletteColors() {
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
                            this.logoBaseCost = parseFloat(data.base_cost_total) || 0;
                            this.logoMarkup = parseFloat(data.markup_amount) || 0;
                            if (data.credit_balance !== undefined) {
                                this.creditBalance = parseFloat(data.credit_balance);
                            }
                        }
                    } catch (e) {
                    } finally {
                        this.logoPriceLoading = false;
                    }
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
                                    bg_color: this.logoBgColor === 'custom' ? this.logoBgCustom : this.logoBgColor,
                                    image_model: this.logoImageModel,
                                    output_format: this.logoImageModel === 'dalle' ? 'raster' : this.logoOutputFormat,
                                    image_format: this.logoImageModel === 'dalle' ? this.logoImageFormat : null,
                                    color_palette: this.logoColorPalette !== 'default' ? this.getSelectedPaletteColors() : null,
                                    recraft_substyle: this.logoImageModel === 'recraft' ? (this.recraftSubstyle || null) : null,
                                    logo_shape: this.logoImageModel === 'recraft' ? this.logoShape : null,
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
                    this.zoomedLogoUrl = this.logoImages[idx].url;
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

                async removeBackground(idx) {
                    const img = this.logoImages[idx];
                    if (!img || img.removingBg) return;

                    this.setLogoImageState(idx, { removingBg: true });

                    try {
                        const response = await fetch('/domain-search/remove-logo-bg', {
                            method: 'POST',
                            headers: this.headers(),
                            body: JSON.stringify({ image_url: img.url }),
                        });

                        if (response.ok) {
                            const data = await response.json();
                            if (data.transparent_url) {
                                this.setLogoImageState(idx, {
                                    url: data.transparent_url,
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
            };
        }
    </script>
</body>
</html>
