{{-- The Logo Studio: the whole generator - prompt, model, style, palette,
     background, size, output, results, similar ideas - as one Alpine
     component. Rendered full screen by logo-studio.blade.php; its behaviour
     is logos.partials.generator-script. Expects $logoUser. --}}
@php
    // The visual vocabulary of the generator, named once. The Logo Lab page defines the same names for its own markup.
    $card = "rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900";
    $label = "block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400";
    $chip = "rounded-lg border px-3 py-2 text-sm font-medium transition";
    $chipOn = "border-blue-600 bg-blue-50 text-blue-700 dark:border-blue-500 dark:bg-blue-500/10 dark:text-blue-300";
    $chipOff = "border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:border-zinc-700 dark:hover:bg-zinc-800";
    $btnPrimary = "inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-zinc-300 dark:disabled:bg-zinc-700";
    $btnSecondary = "inline-flex items-center justify-center gap-2 rounded-lg border border-zinc-200 bg-white px-4 py-2.5 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800";
    $input = "w-full rounded-lg border border-zinc-200 bg-white px-3.5 py-2.5 text-sm text-zinc-900 placeholder-zinc-400 transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-50 dark:placeholder-zinc-500";
@endphp
    <div x-data="logoGenerator()" x-effect="if (outputFormat === 'vector' && logoMode === 'icon_text') logoMode = 'icon_only'" class="space-y-6">

        {{-- Prompt --}}
        <div class="{{ $card }} p-5 sm:p-6">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-center gap-3">
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-zinc-50" x-text="workMode === 'logo' ? 'Vector logo' : 'Raster image'"></h2>
                    <div class="inline-flex rounded-lg border border-zinc-200 bg-zinc-100 p-0.5 dark:border-zinc-800 dark:bg-zinc-950">
                        <button type="button" @click="switchToLogoMode()" class="rounded-md px-3 py-1 text-xs font-medium transition" :class="workMode === 'logo' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-zinc-50' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'">Vector</button>
                        <button type="button" @click="switchToImageMode()" class="rounded-md px-3 py-1 text-xs font-medium transition" :class="workMode === 'image' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-zinc-50' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'">Image</button>
                    </div>
                </div>
                <div class="inline-flex flex-wrap gap-1 rounded-lg border border-zinc-200 bg-zinc-100 p-0.5 dark:border-zinc-800 dark:bg-zinc-950">
                    <button type="button"
                        @click="logoMode = 'icon_only'; logoDomain = ''; if (outputFormat === 'vector' && isTextStyle(logoStyle)) logoStyle = 'default'; fetchLogoPrice()"
                        class="rounded-md px-3 py-1 text-xs font-medium transition" :class="logoMode === 'icon_only' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-zinc-50' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'">Icon only</button>
                    <button type="button" x-show="outputFormat !== 'vector'" x-transition
                        @click="logoMode = 'icon_text'; fetchLogoPrice()"
                        class="rounded-md px-3 py-1 text-xs font-medium transition" :class="logoMode === 'icon_text' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-zinc-50' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'">Icon + text</button>
                    <button type="button"
                        @click="logoMode = 'text_only'; if (logoStyle !== 'default' && !isTextStyle(logoStyle)) logoStyle = 'modern_sans'; fetchLogoPrice()"
                        class="rounded-md px-3 py-1 text-xs font-medium transition" :class="logoMode === 'text_only' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-zinc-50' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'">Text only</button>
                </div>
            </div>

            <div class="mt-4 space-y-3">
                <input type="text" x-model="logoDomain" @input="fetchLogoPrice()" x-show="logoMode !== 'icon_only'" x-transition
                    placeholder="Logo text, e.g. TechStart, CloudSync, DataFlow" class="{{ $input }}">
                <textarea x-model="logoPrompt" @input="fetchLogoPrice()" x-show="logoMode !== 'text_only'" x-transition rows="3"
                    placeholder="Describe the logo: style (modern, vintage, minimalist), mood (professional, playful, elegant), imagery (abstract shapes, tech elements, nature), colours, anything it must include."
                    class="{{ $input }} resize-y leading-relaxed"></textarea>
                <p x-show="logoMode !== 'text_only'" x-transition class="text-xs text-zinc-500 dark:text-zinc-400">Be specific about style, colours and elements for the best results.</p>
            </div>

            <div class="mt-5 flex flex-col gap-4 border-t border-zinc-200 pt-4 dark:border-zinc-800 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-sm">
                    <div class="text-zinc-500 dark:text-zinc-400">
                        Estimated cost
                        <span class="ml-1 font-semibold text-zinc-900 dark:text-zinc-50" x-text="'$' + logoPrice.toFixed(2)"></span>
                        <span class="ml-1 text-zinc-400 dark:text-zinc-500" x-text="'· ' + logoCount + ' logo' + (logoCount > 1 ? 's' : '')"></span>
                    </div>
                    <div class="text-zinc-500 dark:text-zinc-400">
                        Balance
                        <span class="ml-1 font-semibold" :class="creditBalance < 0.01 ? 'text-red-600 dark:text-red-400' : 'text-zinc-900 dark:text-zinc-50'" x-text="'$' + creditBalance.toFixed(4)"></span>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex flex-col items-end gap-1">
                        <button type="button" @click="saveLogoGeneratorSettings()" :disabled="settingsSaving || !canSaveSettings" class="{{ $btnSecondary }}"
                            :title="canSaveSettings ? 'Save these generator settings for next time' : 'Sign in to save generator settings'">
                            <span x-show="!settingsSaving && canSaveSettings">Save settings</span>
                            <span x-show="settingsSaving">Saving…</span>
                            <span x-show="!canSaveSettings">Sign in to save</span>
                        </button>
                        <span x-show="settingsStatus" x-text="settingsStatus" class="text-xs text-emerald-600 dark:text-emerald-400"></span>
                        <span x-show="settingsError" x-text="settingsError" class="text-xs text-red-600 dark:text-red-400"></span>
                    </div>
                    <button type="button" @click="generateLogo()" :disabled="(!logoDomain && !logoPrompt) || generating" class="{{ $btnPrimary }}" data-action="generate">
                        <svg x-show="generating" class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span x-show="!generating && logoBatches.length === 0">Generate logos</span>
                        <span x-show="!generating && logoBatches.length > 0">Generate more</span>
                        <span x-show="generating">Generating…</span>
                    </button>
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[300px_minmax(0,1fr)] xl:grid-cols-[320px_minmax(0,1fr)]">
            {{-- Settings column --}}
            <aside class="space-y-4">

                {{-- Model --}}
                <div class="{{ $card }} p-4">
                    <div class="mb-3 flex items-center justify-between">
                        <span class="{{ $label }}">Model</span>
                        <div x-show="workMode === 'image'" x-transition class="inline-flex rounded-md border border-zinc-200 bg-zinc-100 p-0.5 dark:border-zinc-800 dark:bg-zinc-950">
                            <button type="button" @click="genMode = 'logo'" class="rounded px-2 py-0.5 text-[11px] font-medium transition" :class="genMode === 'logo' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-zinc-50' : 'text-zinc-500 dark:text-zinc-400'">Logo</button>
                            <button type="button" @click="genMode = 'image'" class="rounded px-2 py-0.5 text-[11px] font-medium transition" :class="genMode === 'image' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-zinc-50' : 'text-zinc-500 dark:text-zinc-400'">Image</button>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <button type="button" @click="selectModel('flux')" class="w-full rounded-lg border p-3 text-left transition" :class="selectedModel === 'flux' ? '{{ $chipOn }}' : '{{ $chipOff }}'">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-semibold">Luna</span>
                                <span class="rounded-md bg-zinc-100 px-1.5 py-0.5 text-[11px] font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">Fast</span>
                            </div>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Quick iterations, good quality</p>
                        </button>
                        <button type="button" @click="selectModel('recraft')" class="w-full rounded-lg border p-3 text-left transition" :class="selectedModel === 'recraft' ? '{{ $chipOn }}' : '{{ $chipOff }}'">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-semibold">Ray</span>
                                <span class="rounded-md bg-zinc-100 px-1.5 py-0.5 text-[11px] font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">Balanced</span>
                            </div>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Best quality-to-speed ratio</p>
                        </button>
                        <button type="button" x-show="workMode === 'image'" @click="selectModel('dalle')" class="w-full rounded-lg border p-3 text-left transition" :class="selectedModel === 'dalle' ? '{{ $chipOn }}' : '{{ $chipOff }}'">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-semibold">Cosmo</span>
                                <span class="rounded-md bg-zinc-100 px-1.5 py-0.5 text-[11px] font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">Pro</span>
                            </div>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Highest quality, complex prompts</p>
                        </button>
                    </div>
                    <p x-show="workMode === 'logo'" class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">Vector mode: Ray draws native SVG, Luna's output is vectorised.</p>
                    <p x-show="workMode === 'image'" class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">Image mode: high-resolution raster PNGs for any use.</p>
                </div>

                {{-- Style --}}
                <div class="{{ $card }} p-4">
                    <span class="{{ $label }} mb-3">Style</span>
                    <button type="button" @click="showStyleModal = true" class="group flex w-full items-center gap-3 rounded-lg border border-zinc-200 p-2.5 text-left transition hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:border-zinc-700 dark:hover:bg-zinc-800">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-md bg-zinc-100 dark:bg-zinc-800">
                            <template x-if="logoStyle === 'chrome'">
                                <img src="/images/chrome-preview.svg" alt="Chrome" class="h-full w-full object-cover" />
                            </template>
                            <template x-if="logoStyle !== 'chrome'">
                                <svg class="h-5 w-5 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42"></path></svg>
                            </template>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-semibold text-zinc-900 dark:text-zinc-50" x-text="getStyleLabel()"></div>
                            <div class="truncate text-xs text-zinc-500 dark:text-zinc-400" x-text="'Theme: ' + getThemeLabel()"></div>
                        </div>
                        <svg class="h-4 w-4 text-zinc-400 transition group-hover:text-zinc-600 dark:group-hover:text-zinc-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                    </button>
                </div>

                {{-- Palette --}}
                <div class="{{ $card }} p-4">
                    <span class="{{ $label }} mb-3">Colour palette</span>
                    <button type="button" @click="logoColorPalette = 'none'" class="mb-2 flex w-full items-center gap-2 rounded-lg border p-2 transition" :class="logoColorPalette === 'none' ? '{{ $chipOn }}' : '{{ $chipOff }}'">
                        <div class="flex h-6 flex-1 items-center justify-center rounded-md bg-zinc-100 dark:bg-zinc-800">
                            <span class="text-xs font-medium text-zinc-400 dark:text-zinc-500">Auto</span>
                        </div>
                        <span class="text-xs font-medium">None</span>
                    </button>
                    <div class="grid grid-cols-2 gap-2">
                        <template x-for="p in colorPalettes" :key="p.id">
                            <button type="button" @click="logoColorPalette = p.id" class="rounded-lg border p-2 transition" :class="logoColorPalette === p.id ? '{{ $chipOn }}' : '{{ $chipOff }}'">
                                <div class="flex h-6 overflow-hidden rounded-md">
                                    <template x-for="(c, ci) in p.colors" :key="ci">
                                        <div class="flex-1" :style="'background-color: ' + c"></div>
                                    </template>
                                </div>
                                <div class="mt-1.5 truncate text-center text-xs font-medium" x-text="p.name"></div>
                            </button>
                        </template>
                    </div>
                    <button type="button" @click="logoColorPalette = 'custom'" class="mt-2 flex w-full items-center justify-center gap-2 rounded-lg border py-2 text-xs font-semibold transition" :class="logoColorPalette === 'custom' ? '{{ $chipOn }}' : '{{ $chipOff }}'">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                        Choose custom
                    </button>
                    <div x-show="logoColorPalette === 'custom'" x-transition class="mt-3 rounded-lg bg-zinc-50 p-3 dark:bg-zinc-950">
                        <span class="{{ $label }} mb-2">Custom colours</span>
                        <div class="flex flex-wrap items-center gap-2">
                            <template x-for="(c, ci) in logoCustomColors" :key="ci">
                                <div class="relative">
                                    <div class="h-9 w-9 cursor-pointer rounded-md border border-zinc-200 dark:border-zinc-700" :style="'background-color: ' + c"></div>
                                    <input type="color" :value="normalizeHexColor(c)" @input="logoCustomColors[ci] = normalizeHexColor($event.target.value)" class="absolute inset-0 h-full w-full cursor-pointer opacity-0" />
                                </div>
                            </template>
                            <button type="button" @click="logoCustomColors.length < 5 && logoCustomColors.push('#888888')" x-show="logoCustomColors.length < 5"
                                class="flex h-9 w-9 items-center justify-center rounded-md border border-dashed border-zinc-300 text-zinc-400 transition hover:border-zinc-400 hover:text-zinc-600 dark:border-zinc-700 dark:hover:border-zinc-600 dark:hover:text-zinc-300">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                            </button>
                            <button type="button" @click="logoCustomColors.length > 2 && logoCustomColors.pop()" x-show="logoCustomColors.length > 2"
                                class="flex h-9 w-9 items-center justify-center rounded-md border border-dashed border-zinc-300 text-zinc-400 transition hover:border-zinc-400 hover:text-zinc-600 dark:border-zinc-700 dark:hover:border-zinc-600 dark:hover:text-zinc-300">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path></svg>
                            </button>
                        </div>
                        <div x-show="canManagePalettes" class="mt-3 border-t border-zinc-200 pt-3 dark:border-zinc-800">
                            <span class="{{ $label }} mb-2">Save palette</span>
                            <div class="flex items-center gap-2">
                                <input type="text" x-model="savedPaletteName" maxlength="60" placeholder="Palette name" class="{{ $input }} py-2 text-xs" />
                                <button type="button" @click="saveCurrentPalette()" :disabled="paletteSaving" class="{{ $btnPrimary }} px-3 py-2 text-xs" x-text="paletteSaving ? 'Saving…' : 'Save'"></button>
                            </div>
                            <p class="mt-1 text-xs text-red-600 dark:text-red-400" x-show="paletteError" x-text="paletteError"></p>
                            <p class="mt-1 text-xs text-blue-600 dark:text-blue-400" x-show="paletteSuccess" x-text="paletteSuccess"></p>
                            <div class="mt-2 space-y-1">
                                <template x-for="palette in savedPalettes" :key="palette.id">
                                    <div class="flex items-center gap-1 rounded-md border border-zinc-200 bg-white p-1.5 dark:border-zinc-800 dark:bg-zinc-900">
                                        <button type="button" @click="applySavedPalette(palette)" class="flex min-w-0 flex-1 items-center gap-2 text-left">
                                            <div class="flex h-4 w-14 overflow-hidden rounded border border-zinc-200 dark:border-zinc-700">
                                                <template x-for="(c, ci) in palette.colors" :key="ci">
                                                    <div class="flex-1" :style="'background-color: ' + c"></div>
                                                </template>
                                            </div>
                                            <span class="truncate text-xs text-zinc-700 dark:text-zinc-300" x-text="palette.name"></span>
                                        </button>
                                        <button type="button" @click.stop="deleteSavedPalette(palette.id)" class="rounded p-1 text-zinc-400 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10" title="Delete palette">
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7h6m-7 0V5a1 1 0 011-1h4a1 1 0 011 1v2"></path></svg>
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Background --}}
                <div class="{{ $card }} p-4">
                    <span class="{{ $label }} mb-3">Background</span>
                    <div class="grid grid-cols-3 gap-2">
                        <button type="button" @click="backgroundColor = 'white'; fetchLogoPrice()" class="{{ $chip }}" :class="backgroundColor === 'white' ? '{{ $chipOn }}' : '{{ $chipOff }}'">White</button>
                        <button type="button" @click="backgroundColor = 'none'; fetchLogoPrice()" class="{{ $chip }}" :class="backgroundColor === 'none' ? '{{ $chipOn }}' : '{{ $chipOff }}'">None</button>
                        <div class="relative">
                            <button type="button" @click="selectCustomBackground()" class="{{ $chip }} flex w-full items-center justify-center gap-2" :class="isCustomBackgroundColor() ? '{{ $chipOn }}' : '{{ $chipOff }}'">
                                <span class="inline-block h-3.5 w-3.5 rounded border border-zinc-300 dark:border-zinc-600" :style="'background-color: ' + backgroundCustomColor"></span>
                                Colour
                            </button>
                            <input type="color" :value="normalizeHexColor(backgroundCustomColor, '#4F46E5')" @input="applyCustomBackgroundColor($event.target.value)" class="absolute inset-0 h-full w-full cursor-pointer opacity-0" aria-label="Pick background colour" />
                        </div>
                    </div>
                </div>

                {{-- Image size (raster image content only) --}}
                <div x-show="workMode === 'image' && genMode === 'image'" x-transition class="{{ $card }} p-4">
                    <span class="{{ $label }} mb-3">Image size</span>
                    <div class="grid grid-cols-2 gap-2">
                        <template x-for="sz in imageSizeOptions()" :key="sz.id">
                            <button type="button" @click="imageSize = sz.id; fetchLogoPrice()" class="{{ $chip }} text-center" :class="imageSize === sz.id ? '{{ $chipOn }}' : '{{ $chipOff }}'">
                                <span class="block text-sm font-semibold" x-text="sz.label"></span>
                                <span class="block text-xs opacity-70" x-text="sz.id"></span>
                            </button>
                        </template>
                    </div>
                </div>

                {{-- Output: count, detail, shape, PRO --}}
                <div class="{{ $card }} space-y-5 p-4">
                    <div>
                        <span class="{{ $label }} mb-3">Number of logos</span>
                        <div class="grid grid-cols-4 gap-2">
                            <template x-for="num in [1,2,3,4]">
                                <button type="button" @click="logoCount = num; fetchLogoPrice()" class="{{ $chip }} text-center" :class="logoCount === num ? '{{ $chipOn }}' : '{{ $chipOff }}'" x-text="num"></button>
                            </template>
                        </div>
                    </div>
                    <div x-show="outputFormat !== 'vector'" x-transition>
                        <span class="{{ $label }} mb-3">Detail level</span>
                        <div class="grid grid-cols-3 gap-2">
                            <template x-for="level in [{id:'min',label:'Minimal'},{id:'medium',label:'Medium'},{id:'max',label:'Maximum'}]" :key="level.id">
                                <button type="button" @click="detailLevel = level.id; fetchLogoPrice();" class="{{ $chip }} text-center" :class="detailLevel === level.id ? '{{ $chipOn }}' : '{{ $chipOff }}'" x-text="level.label"></button>
                            </template>
                        </div>
                        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400" x-show="selectedModel === 'flux'">Detail level applies to Luna.</p>
                    </div>
                    <div x-show="outputFormat !== 'vector'" x-transition>
                        <span class="{{ $label }} mb-3">Shape</span>
                        <div class="grid grid-cols-3 gap-2">
                            <template x-for="shape in [{id:'',label:'None'},{id:'circle',label:'Circle'},{id:'square',label:'Square'},{id:'hexagon',label:'Hexagon'},{id:'triangle',label:'Triangle'},{id:'pentagon',label:'Pentagon'}]" :key="shape.id">
                                <button type="button" @click="shapeContainer = shape.id; fetchLogoPrice(); saveLogoGeneratorSettings()" class="{{ $chip }} text-center" :class="shapeContainer === shape.id ? '{{ $chipOn }}' : '{{ $chipOff }}'" x-text="shape.label"></button>
                            </template>
                        </div>
                        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">The logo is kept inside the chosen shape.</p>
                    </div>
                    <div x-show="!isLunaVectorMode() && !(selectedModel === 'recraft' && outputFormat === 'vector')">
                        <button type="button" @click="proMode = !proMode; ensureSupportedImageSize(); fetchLogoPrice()" class="flex w-full items-center justify-between rounded-lg border p-3 text-left transition" :class="proMode ? '{{ $chipOn }}' : '{{ $chipOff }}'">
                            <span class="text-sm font-semibold" x-text="selectedModel === 'dalle' ? 'HD quality' : 'PRO mode'"></span>
                            <span class="relative inline-flex h-5 w-9 items-center rounded-full transition" :class="proMode ? 'bg-blue-600' : 'bg-zinc-300 dark:bg-zinc-700'">
                                <span class="inline-block h-4 w-4 rounded-full bg-white shadow transition" :class="proMode ? 'translate-x-[18px]' : 'translate-x-0.5'"></span>
                            </span>
                        </button>
                        <div x-show="proMode && selectedModel === 'flux'" x-transition class="mt-3">
                            <span class="{{ $label }} mb-2">PRO resolution</span>
                            <div class="grid grid-cols-3 gap-2">
                                <button type="button" @click="proSize = '512'; fetchLogoPrice()" class="{{ $chip }} text-center text-xs" :class="proSize === '512' ? '{{ $chipOn }}' : '{{ $chipOff }}'">512</button>
                                <button type="button" @click="proSize = '1024'; fetchLogoPrice()" class="{{ $chip }} text-center text-xs" :class="proSize === '1024' ? '{{ $chipOn }}' : '{{ $chipOff }}'">1024</button>
                                <button type="button" @click="proSize = '1536'; fetchLogoPrice()" class="{{ $chip }} text-center text-xs" :class="proSize === '1536' ? '{{ $chipOn }}' : '{{ $chipOff }}'">1536</button>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>

            {{-- Results column --}}
            <div class="min-w-0">
                <div x-show="error" x-cloak class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-500/30 dark:bg-red-500/10">
                    <div class="flex items-start gap-3">
                        <svg class="mt-0.5 h-5 w-5 text-red-600 dark:text-red-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                        </svg>
                        <div class="flex-1">
                            <h4 class="text-sm font-semibold text-red-900 dark:text-red-200">Something went wrong</h4>
                            <p class="mt-1 text-sm text-red-700 dark:text-red-300" x-text="error"></p>
                        </div>
                        <button type="button" @click="error = null" class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-200">
                            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                        </button>
                    </div>
                </div>

                {{-- Empty state --}}
                <div x-show="logoBatches.length === 0" class="{{ $card }} flex flex-col items-center justify-center px-6 py-24 text-center">
                    <div class="mb-5 flex h-14 w-14 items-center justify-center rounded-xl border border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-950">
                        <svg class="h-7 w-7 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 008 10.172V5L7 4z"/>
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-50">Ready when you are</h3>
                    <p class="mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">Describe the logo above, set the model, style and palette on the left, then generate. Your results land here.</p>
                </div>

                {{-- Results --}}
                <div x-show="logoBatches.length > 0" class="space-y-8">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-50">Generated logos</h2>
                    <template x-for="(batch, batchIndex) in logoBatches" :key="batch.id">
                        <div class="space-y-4">
                            <div x-show="batchIndex > 0" class="flex items-center gap-4 py-2">
                                <div class="h-px flex-1 bg-zinc-200 dark:bg-zinc-800"></div>
                                <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400" x-text="'Previous generation · ' + new Date(batch.timestamp).toLocaleString()"></div>
                                <div class="h-px flex-1 bg-zinc-200 dark:bg-zinc-800"></div>
                            </div>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                                <template x-if="batch.loading">
                                    <template x-for="n in batch.expectedCount" :key="n">
                                        <div class="{{ $card }} overflow-hidden">
                                            <div class="relative flex aspect-square items-center justify-center bg-zinc-50 dark:bg-zinc-950">
                                                <div class="text-center">
                                                    <svg class="mx-auto h-8 w-8 animate-spin text-blue-600 dark:text-blue-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                    </svg>
                                                    <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">Generating…</p>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </template>
                                <template x-for="(image, imageIndex) in batch.images" :key="image.key || image.editUrl || image.url || imageIndex">
                                    <div class="{{ $card }} overflow-hidden transition hover:border-zinc-300 dark:hover:border-zinc-700">
                                        <template x-if="image.failed">
                                            <div class="relative flex aspect-square items-center justify-center bg-zinc-50 dark:bg-zinc-950">
                                                <div class="text-center">
                                                    <svg class="mx-auto h-10 w-10 text-red-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                                    <p class="mt-3 px-4 text-sm text-red-600 dark:text-red-400" x-text="image.error || 'Failed to generate'"></p>
                                                </div>
                                            </div>
                                        </template>
                                        <div x-show="!image.failed" class="group relative aspect-square cursor-pointer bg-white dark:bg-zinc-100" @click="zoomImage(image.displayUrl || image.url)">
                                            <img :src="image.displayUrl || image.url" :alt="'Logo ' + (imageIndex + 1)" class="h-full w-full object-contain p-4" loading="lazy">
                                            <div class="absolute left-2 top-2 flex flex-wrap gap-1.5">
                                                <span class="rounded-md bg-zinc-900/85 px-2 py-0.5 text-[11px] font-medium text-white" x-text="image.metadata?.model || batch.metadata?.model || 'Luna'"></span>
                                                <span class="rounded-md bg-zinc-900/85 px-2 py-0.5 text-[11px] font-medium text-white" x-text="image.metadata?.resolution || batch.metadata?.resolution || '512x512'"></span>
                                            </div>
                                            <div class="absolute right-2 top-2 flex flex-wrap justify-end gap-1.5">
                                                <span class="rounded-md bg-zinc-900/85 px-2 py-0.5 text-[11px] font-medium capitalize text-white" x-text="image.metadata?.style || batch.metadata?.style || 'professional'"></span>
                                                <span class="rounded-md bg-blue-600 px-2 py-0.5 text-[11px] font-semibold text-white" x-text="'$' + (image.metadata?.price || batch.metadata?.price || '0.00')"></span>
                                            </div>
                                        </div>
                                        <div x-show="!image.failed" class="grid gap-2 border-t border-zinc-200 p-3 dark:border-zinc-800">
                                            <button type="button" @click="saveLogo(image.editUrl || image.url)" class="{{ $btnPrimary }} py-2">Save</button>
                                            <button type="button" x-show="!image.isVector" @click.stop="upscaleGeneratedImage(batchIndex, imageIndex)" :disabled="image.upscaling" class="{{ $btnSecondary }} py-2"
                                                x-text="image.upscaling ? 'Upsizing…' : 'Upsize ($' + upscalePrice.toFixed(2) + ')'"></button>
                                            <p x-show="image.upscaleError" x-text="image.upscaleError" class="text-xs text-red-600 dark:text-red-400"></p>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Similar ideas --}}
                <div x-show="similarIdeas.length > 0" class="mt-10 space-y-4">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-50">Similar ideas</h2>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                        <template x-for="idea in similarIdeas" :key="idea.id">
                            <div class="{{ $card }} overflow-hidden">
                                <div class="relative aspect-square cursor-pointer bg-zinc-50 dark:bg-zinc-950" @click="zoomImage(idea.prompt_outputs[0].url)">
                                    <img :src="idea.prompt_outputs[0].url" :alt="idea.query" class="h-full w-full object-contain p-4" loading="lazy">
                                </div>
                                <div class="border-t border-zinc-200 p-3 dark:border-zinc-800">
                                    <p class="line-clamp-2 text-sm text-zinc-700 dark:text-zinc-300" x-text="idea.query"></p>
                                    <button type="button" @click="loadFromSimilar(idea)" class="{{ $btnSecondary }} mt-3 w-full py-2">Load this</button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        @include('logos.partials.style-modal')

        {{-- Zoom --}}
        <div x-show="zoomImageUrl" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/85 p-4" @click="zoomImageUrl = null">
            <div class="w-full max-w-5xl" @click.stop>
                <img :src="zoomImageUrl" alt="Zoomed logo" class="h-auto w-full rounded-lg shadow-xl">
            </div>
        </div>
    </div>
