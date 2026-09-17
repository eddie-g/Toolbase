<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/netkit_logo_cube.svg') }}">
    <script>if(localStorage.getItem('darkMode')==='true')document.documentElement.classList.add('dark');</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AI Logo Lab - Netkit</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Abril+Fatface&family=Anton&family=Arvo:wght@400;700&family=Bebas+Neue&family=Bitter:wght@400;700&family=Bungee&family=Cabin:wght@400;700&family=Cinzel:wght@400;700&family=Comfortaa:wght@400;700&family=Cormorant+Garamond:wght@400;700&family=Dancing+Script:wght@400;700&family=DM+Sans:wght@400;700&family=Exo+2:wght@400;700&family=Fira+Sans:wght@400;700&family=IBM+Plex+Sans:wght@400;700&family=Inter:wght@400;700&family=Josefin+Sans:wght@400;700&family=Lato:wght@400;700&family=Libre+Baskerville:wght@400;700&family=Lobster&family=Macondo&family=Merriweather:wght@400;700&family=Montserrat:wght@400;700&family=Nunito:wght@400;700&family=Open+Sans:wght@400;700&family=Oswald:wght@400;700&family=Playfair+Display:wght@400;700&family=Poppins:wght@400;700&family=Raleway:wght@400;700&family=Roboto:wght@400;700&family=Rubik:wght@400;700&family=Source+Sans+3:wght@400;700&family=Space+Grotesk:wght@400;700&family=Work+Sans:wght@400;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        [x-cloak] { display: none !important; }
        .selection-box { vector-effect: non-scaling-stroke; }
        .style-sample-image { transition: transform 0.25s ease, filter 0.25s ease; }
        .group:hover .style-sample-image { transform: scale(1.06); filter: saturate(1.08); }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-950">
    <x-site-header :compact="true" />
    
    @if ($logoUser ?? false)
    <div x-data="logoGenerator()" x-effect="if (outputFormat === 'vector' && logoMode === 'icon_text') logoMode = 'icon_only'" class="min-h-screen flex flex-col">
        <!-- Top Bar -->
        <div class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 mt-[70px]">
            <div id="subpanel-bar" class="px-3 md:px-6 py-3 md:py-4 flex items-center justify-between gap-2">
                <div class="flex items-center gap-2 md:gap-3 min-w-0">
                    <!-- Hamburger Menu Toggle -->
                    <button 
                        @click="showLeftPanel = !showLeftPanel"
                        class="flex-shrink-0 p-2 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors text-gray-700 dark:text-gray-300"
                        :aria-label="showLeftPanel ? 'Hide sidebar' : 'Show sidebar'"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                    
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h1 class="text-base md:text-xl font-bold text-gray-900 dark:text-white truncate" x-text="workMode === 'logo' ? 'Logo Generator' : 'Image Generator'"></h1>
                        </div>
                    </div>
                    
                    <!-- Visual Separator -->
                    <div class="hidden lg:block h-8 w-px bg-gray-300 dark:bg-gray-600 mx-4"></div>
                    
                    <!-- Image/Logo Mode Toggle -->
                    <div class="flex items-center gap-2 bg-blue-50 dark:bg-blue-900/20 border-2 border-blue-200 dark:border-blue-800 rounded-lg p-1">
                        <button
                            @click="switchToImageMode()"
                            :class="workMode === 'image' ? 'bg-white dark:bg-gray-700 shadow-sm' : 'text-gray-600 dark:text-gray-400'"
                            class="px-3 md:px-4 py-2 rounded-md text-sm font-semibold transition-all"
                        >
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                                <span class="hidden sm:inline">Image</span>
                            </div>
                        </button>
                        <button
                            @click="switchToLogoMode()"
                            :class="workMode === 'logo' ? 'bg-white dark:bg-gray-700 shadow-sm' : 'text-gray-600 dark:text-gray-400'"
                            class="px-3 md:px-4 py-2 rounded-md text-sm font-semibold transition-all"
                        >
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 008 10.172V5L7 4z"/>
                                </svg>
                                <span class="hidden sm:inline">Vector</span>
                            </div>
                        </button>
                    </div>
                    
                </div>
                
                <!-- Generation Settings Toggle & Generate Button -->
                <div class="flex items-center gap-2 md:gap-4">
                    <div class="hidden md:block text-right">
                        <div class="flex items-center justify-end gap-2">
                            <div class="text-sm text-gray-500 dark:text-gray-400">Estimated Cost</div>
                            <span class="px-2 py-0.5 bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-400 text-xs font-semibold rounded" x-text="logoCount + ' logo' + (logoCount > 1 ? 's' : '')"></span>
                        </div>
                        <div class="text-lg font-bold text-gray-900 dark:text-white" x-text="'$' + logoPrice.toFixed(2)"></div>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <button
                            type="button"
                            @click="saveLogoGeneratorSettings()"
                            :disabled="settingsSaving || !canSaveSettings"
                            class="px-3 md:px-5 py-2 md:py-3 rounded-lg bg-emerald-600 hover:bg-emerald-700 disabled:bg-gray-300 disabled:cursor-not-allowed text-white text-sm md:text-base font-semibold shadow-lg transition-colors whitespace-nowrap"
                            :title="canSaveSettings ? 'Save these generator settings for next time' : 'Sign in to save generator settings'"
                        >
                            <span x-show="!settingsSaving && canSaveSettings" class="hidden sm:inline">Save Settings</span>
                            <span x-show="!settingsSaving && canSaveSettings" class="sm:hidden">Save</span>
                            <span x-show="settingsSaving">Saving...</span>
                            <span x-show="!canSaveSettings">Sign in to Save</span>
                        </button>
                        <span x-show="settingsStatus" x-text="settingsStatus" class="hidden xl:block text-xs text-emerald-600 dark:text-emerald-400"></span>
                        <span x-show="settingsError" x-text="settingsError" class="hidden xl:block text-xs text-red-600 dark:text-red-400"></span>
                    </div>
                    <button 
                        @click="generateLogo()"
                        :disabled="(!logoDomain && !logoPrompt) || generating"
                        class="px-3 md:px-6 py-2 md:py-3 bg-violet-600 hover:bg-violet-700 disabled:bg-gray-300 disabled:cursor-not-allowed text-white text-sm md:text-base font-semibold rounded-lg transition-colors shadow-lg whitespace-nowrap flex items-center gap-2"
                    >
                        <svg x-show="generating" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span x-show="!generating && logoBatches.length === 0" class="hidden md:inline">Generate Logos</span>
                        <span x-show="!generating && logoBatches.length === 0" class="md:hidden">Generate</span>
                        <span x-show="!generating && logoBatches.length > 0" class="hidden md:inline">Generate More</span>
                        <span x-show="!generating && logoBatches.length > 0" class="md:hidden">More</span>
                        <span x-show="generating" class="hidden md:inline">Generating...</span>
                        <span x-show="generating" class="md:hidden">...</span>
                    </button>
                    <button 
                        @click="showGenerationSettings = !showGenerationSettings"
                        class="hidden lg:flex p-2 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors text-gray-700 dark:text-gray-300"
                        title="Settings"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Generation Settings Slide-out Panel -->
            <div 
                x-show="showGenerationSettings"
                @click.away="showGenerationSettings = false"
                x-transition:enter="transition-transform ease-out duration-300"
                x-transition:enter-start="translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition-transform ease-in duration-200"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="translate-x-full"
                class="fixed top-[70px] right-0 h-[calc(100vh-70px)] w-80 bg-white dark:bg-gray-800 shadow-2xl z-40 overflow-y-auto border-l border-gray-200 dark:border-gray-700"
                x-cloak
            >
                <div class="p-6 space-y-6">
                    <!-- Panel Header -->
                    <div class="flex items-center justify-between pb-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Generation Settings</h3>
                        <button @click="showGenerationSettings = false" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <!-- PRO Mode Toggle -->
                    <div x-show="!isLunaVectorMode() && !(selectedModel === 'recraft' && outputFormat === 'vector')">
                        <div class="flex items-center justify-between p-3.5 rounded-xl border-2 transition-all cursor-pointer" :class="proMode ? 'border-amber-400 bg-gradient-to-r from-amber-50 to-orange-50 dark:from-amber-900/20 dark:to-orange-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'" @click="proMode = !proMode; ensureSupportedImageSize(); fetchLogoPrice()">
                            <div class="flex items-center gap-3">
                                <div class="text-sm font-semibold text-gray-900 dark:text-white" x-text="selectedModel === 'dalle' ? 'HD Quality' : 'PRO Mode'"></div>
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider" :class="proMode ? 'bg-amber-500 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-500 dark:text-gray-400'" x-text="proMode ? 'ON' : 'OFF'"></span>
                            </div>
                            <div class="relative">
                                <div class="w-11 h-6 rounded-full transition-colors" :class="proMode ? 'bg-amber-500' : 'bg-gray-300 dark:bg-gray-600'">
                                    <div class="absolute top-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" :class="proMode ? 'translate-x-[22px]' : 'translate-x-0.5'"></div>
                                </div>
                            </div>
                        </div>
                        <div x-show="proMode && selectedModel === 'flux'" x-transition class="mt-3">
                            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-2">PRO Resolution</label>
                            <div class="flex gap-2">
                                <button type="button" @click="proSize = '512'; fetchLogoPrice()" class="flex-1 py-2 rounded-lg border text-xs font-semibold transition" :class="proSize === '512' ? 'border-amber-500 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-600'">512</button>
                                <button type="button" @click="proSize = '1024'; fetchLogoPrice()" class="flex-1 py-2 rounded-lg border text-xs font-semibold transition" :class="proSize === '1024' ? 'border-amber-500 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-600'">1024</button>
                                <button type="button" @click="proSize = '1536'; fetchLogoPrice()" class="flex-1 py-2 rounded-lg border text-xs font-semibold transition" :class="proSize === '1536' ? 'border-amber-500 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-600'">1536</button>
                            </div>
                        </div>
                    </div>

                    <!-- Number of Logos -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-900 dark:text-white mb-3">Number of Logos</label>
                        <div class="flex gap-2">
                            <template x-for="num in [1,2,3,4]">
                                <button 
                                    @click="logoCount = num; fetchLogoPrice()"
                                    :class="logoCount === num ? 'bg-violet-600 text-white border-violet-600' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600'"
                                    class="flex-1 px-4 py-3 border rounded-lg font-medium transition-colors"
                                    x-text="num"
                                ></button>
                            </template>
                        </div>
                    </div>

                    <!-- Detail Level -->
                    <div x-show="outputFormat !== 'vector'" x-transition>
                        <label class="block text-sm font-semibold text-gray-900 dark:text-white mb-3">Detail Level</label>
                        <div class="flex gap-2">
                            <template x-for="level in [{id:'min',label:'Minimal'},{id:'medium',label:'Medium'},{id:'max',label:'Maximum'}]" :key="level.id">
                                <button 
                                    type="button"
                                    @click="detailLevel = level.id; fetchLogoPrice();"
                                    :class="detailLevel === level.id ? 'bg-violet-600 text-white border-violet-600' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600'"
                                    class="flex-1 px-3 py-2.5 border rounded-lg font-medium text-sm transition-colors"
                                    x-text="level.label"
                                ></button>
                            </template>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2" x-show="selectedModel === 'flux'">Detail level available for Luna</p>
                    </div>

                    <!-- Shape Container -->
                    <div x-show="outputFormat !== 'vector'" x-transition>
                        <label class="block text-sm font-semibold text-gray-900 dark:text-white mb-3">Shape</label>
                        <div class="grid grid-cols-3 gap-2">
                            <template x-for="shape in [{id:'',label:'None'},{id:'circle',label:'Circle'},{id:'square',label:'Square'},{id:'hexagon',label:'Hexagon'},{id:'triangle',label:'Triangle'},{id:'pentagon',label:'Pentagon'}]" :key="shape.id">
                                <button 
                                    type="button"
                                    @click="shapeContainer = shape.id; fetchLogoPrice(); saveLogoGeneratorSettings()"
                                    :class="shapeContainer === shape.id ? 'bg-violet-600 text-white border-violet-600' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600'"
                                    class="px-3 py-2.5 border rounded-lg font-medium text-sm transition-colors"
                                    x-text="shape.label"
                                ></button>
                            </template>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Logo will be constrained inside the selected shape</p>
                    </div>
                </div>
            </div>

        </div>

        <!-- Main Content -->
        <div class="flex flex-1 overflow-hidden relative">
            <!-- Left Sidebar -->
            <div 
                x-show="showLeftPanel"
                x-transition:enter="transition-transform ease-out duration-200"
                x-transition:enter-start="-translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition-transform ease-in duration-150"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="-translate-x-full"
                class="w-80 lg:w-96 bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 overflow-y-auto"
                x-cloak
            >
                <div class="p-6 space-y-6">
                    <!-- Balance Display -->
                    <div class="bg-gradient-to-r from-violet-50 to-purple-50 border border-violet-200 rounded-lg px-4 py-3 flex items-center justify-between">
                        <span class="text-xs font-medium text-violet-700 uppercase tracking-wider">Balance</span>
                        <span class="text-lg font-bold" :class="creditBalance < 0.01 ? 'text-red-600' : 'text-violet-600'" x-text="'$' + creditBalance.toFixed(4)"></span>
                    </div>

                    <!-- AI Model Selector -->
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-semibold text-gray-900">AI Model</h3>
                            <!-- Content mode switch: Logo vs Image (image/raster workMode only) -->
                            <div x-show="workMode === 'image'" x-transition
                                class="inline-flex rounded-lg border border-gray-200 overflow-hidden">
                                <button type="button" @click="genMode = 'logo'"
                                    class="px-3 py-1 text-xs font-semibold transition-all"
                                    :class="genMode === 'logo' ? 'bg-violet-600 text-white' : 'bg-white text-gray-500 hover:text-gray-700'">Logo</button>
                                <button type="button" @click="genMode = 'image'"
                                    class="px-3 py-1 text-xs font-semibold transition-all"
                                    :class="genMode === 'image' ? 'bg-violet-600 text-white' : 'bg-white text-gray-500 hover:text-gray-700'">Image</button>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <!-- Fast: Luna -->
                            <button 
                                @click="selectModel('flux')"
                                :class="selectedModel === 'flux' ? 'ring-2 ring-blue-500 bg-blue-50' : 'hover:bg-gray-50'"
                                class="w-full p-4 border border-gray-200 rounded-lg text-left transition-all"
                            >
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="font-semibold text-gray-900">Luna</span>
                                            <span class="px-2 py-0.5 bg-blue-100 text-blue-700 text-xs font-medium rounded">Fast</span>
                                        </div>
                                        <p class="text-sm text-gray-600 mt-1">Quick iterations, good quality</p>
                                    </div>
                                </div>
                            </button>

                            <!-- Balanced: Ray -->
                            <button 
                                @click="selectModel('recraft')"
                                :class="selectedModel === 'recraft' ? 'ring-2 ring-blue-500 bg-blue-50' : 'hover:bg-gray-50'"
                                class="w-full p-4 border border-gray-200 rounded-lg text-left transition-all"
                            >
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="font-semibold text-gray-900">Ray</span>
                                            <span class="px-2 py-0.5 bg-violet-100 text-violet-700 text-xs font-medium rounded">Balanced</span>
                                        </div>
                                        <p class="text-sm text-gray-600 mt-1">Best quality-to-speed ratio</p>
                                    </div>
                                </div>
                            </button>

                            <!-- Pro: Cosmo (Hidden in Logo mode) -->
                            <button 
                                x-show="workMode === 'image'"
                                @click="selectModel('dalle')"
                                :class="selectedModel === 'dalle' ? 'ring-2 ring-blue-500 bg-blue-50' : 'hover:bg-gray-50'"
                                class="w-full p-4 border border-gray-200 rounded-lg text-left transition-all"
                            >
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="font-semibold text-gray-900">Cosmo</span>
                                            <span class="px-2 py-0.5 bg-amber-100 text-amber-700 text-xs font-medium rounded">Pro</span>
                                        </div>
                                        <p class="text-sm text-gray-600 mt-1">Highest quality, complex prompts</p>
                                    </div>
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- Style -->
                    <div>
                        <button type="button" @click="showStyleModal = true"
                            class="mb-3 w-full bg-white rounded-xl shadow border border-gray-200 p-3 flex items-center gap-3 hover:border-gray-300 transition-all group text-left">
                            <div class="w-10 h-10 rounded-lg overflow-hidden flex-shrink-0 bg-gray-100 flex items-center justify-center">
                                <template x-if="logoStyle === 'chrome'">
                                    <img src="/images/chrome-preview.svg" alt="Chrome" class="w-full h-full object-cover" />
                                </template>
                                <template x-if="logoStyle !== 'chrome'">
                                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42"></path></svg>
                                </template>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="text-[10px] font-medium text-gray-400 uppercase tracking-wider">Style</div>
                                <div class="text-sm font-semibold text-gray-900 truncate" x-text="getStyleLabel()"></div>
                                <div class="mt-0.5 text-xs text-gray-500 truncate" x-text="'Theme: ' + getThemeLabel()"></div>
                            </div>
                            <svg class="w-4 h-4 text-gray-400 group-hover:text-gray-500 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                        </button>
                    </div>

                    <!-- Color Palette -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-900 mb-3">Color Palette</label>
                        <button type="button" @click="logoColorPalette = 'none'"
                            class="w-full mb-2 rounded-xl border-2 p-2 transition-all flex items-center gap-2"
                            :class="logoColorPalette === 'none' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="flex-1 h-6 rounded-md bg-gray-100 flex items-center justify-center">
                                <span class="text-xs font-medium text-gray-400">AI Picks</span>
                            </div>
                            <div class="text-xs font-medium" :class="logoColorPalette === 'none' ? 'text-violet-700' : 'text-gray-500'">None</div>
                        </button>
                        <div class="grid grid-cols-2 gap-2">
                            <template x-for="p in colorPalettes" :key="p.id">
                                <button type="button" @click="logoColorPalette = p.id"
                                    class="rounded-xl border-2 p-2 transition-all"
                                    :class="logoColorPalette === p.id ? 'border-blue-500 ring-2 ring-blue-200' : 'border-gray-200 hover:border-gray-300'">
                                    <div class="flex h-6 rounded-md overflow-hidden">
                                        <template x-for="(c, ci) in p.colors" :key="ci">
                                            <div class="flex-1" :style="'background-color: ' + c"></div>
                                        </template>
                                    </div>
                                    <div class="mt-1.5 text-xs font-medium text-center truncate" :class="logoColorPalette === p.id ? 'text-violet-700' : 'text-gray-500'" x-text="p.name"></div>
                                </button>
                            </template>
                        </div>
                        <button type="button" @click="logoColorPalette = 'custom'"
                            class="mt-2 w-full py-2.5 rounded-xl border-2 text-xs font-semibold transition-all flex items-center justify-center gap-2"
                            :class="logoColorPalette === 'custom' ? 'border-violet-500 bg-violet-50 text-violet-700' : 'border-gray-200 text-gray-600 hover:border-gray-300'">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                            Choose Custom
                        </button>
                        <div x-show="logoColorPalette === 'custom'" x-transition class="mt-3 p-3 bg-gray-50 rounded-xl">
                            <label class="block text-xs font-medium text-gray-600 mb-2">Custom Colors</label>
                            <div class="flex items-center gap-2 flex-wrap">
                                <template x-for="(c, ci) in logoCustomColors" :key="ci">
                                    <div class="relative">
                                        <div class="w-10 h-10 rounded-lg border-2 border-gray-300 cursor-pointer shadow-sm" :style="'background-color: ' + c"></div>
                                        <input type="color" :value="normalizeHexColor(c)" @input="logoCustomColors[ci] = normalizeHexColor($event.target.value)" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" />
                                    </div>
                                </template>
                                <button type="button" @click="logoCustomColors.length < 5 && logoCustomColors.push('#888888')" x-show="logoCustomColors.length < 5"
                                    class="w-10 h-10 rounded-lg border-2 border-dashed border-gray-300 flex items-center justify-center text-gray-400 hover:border-gray-400 hover:text-gray-500 transition">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                                </button>
                                <button type="button" @click="logoCustomColors.length > 2 && logoCustomColors.pop()" x-show="logoCustomColors.length > 2"
                                    class="w-10 h-10 rounded-lg border-2 border-dashed border-gray-300 flex items-center justify-center text-gray-400 hover:border-gray-400 hover:text-gray-500 transition">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path></svg>
                                </button>
                            </div>
                            <div x-show="canManagePalettes" class="mt-3 pt-3 border-t border-gray-200">
                                <label class="block text-xs font-medium text-gray-600 mb-2">Save Palette</label>
                                <div class="flex items-center gap-2">
                                    <input type="text" x-model="savedPaletteName" maxlength="60" placeholder="Palette name"
                                        class="flex-1 px-3 py-2 rounded-lg border border-gray-300 bg-white text-xs text-gray-700 placeholder-gray-400 focus:ring-2 focus:ring-violet-500 focus:border-transparent transition" />
                                    <button type="button" @click="saveCurrentPalette()"
                                        class="px-3 py-2 rounded-lg text-xs font-semibold transition"
                                        :class="paletteSaving ? 'bg-gray-300 text-gray-500 cursor-not-allowed' : 'bg-violet-600 hover:bg-violet-700 text-white'"
                                        :disabled="paletteSaving"
                                        x-text="paletteSaving ? 'Saving...' : 'Save'"></button>
                                </div>
                                <p class="mt-1 text-xs text-red-500" x-show="paletteError" x-text="paletteError"></p>
                                <p class="mt-1 text-xs text-violet-600" x-show="paletteSuccess" x-text="paletteSuccess"></p>

                                <div class="mt-2 space-y-1">
                                    <template x-for="palette in savedPalettes" :key="palette.id">
                                        <div class="flex items-center gap-1 rounded-lg border border-gray-200 bg-white p-1.5">
                                            <button type="button" @click="applySavedPalette(palette)" class="flex-1 min-w-0 flex items-center gap-2 text-left">
                                                <div class="flex h-4 w-14 rounded overflow-hidden border border-gray-200">
                                                    <template x-for="(c, ci) in palette.colors" :key="ci">
                                                        <div class="flex-1" :style="'background-color: ' + c"></div>
                                                    </template>
                                                </div>
                                                <span class="text-xs text-gray-700 truncate" x-text="palette.name"></span>
                                            </button>
                                            <button type="button" @click.stop="deleteSavedPalette(palette.id)"
                                                class="p-1 rounded text-gray-400 hover:text-red-500 hover:bg-red-50 transition"
                                                title="Delete palette">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7h6m-7 0V5a1 1 0 011-1h4a1 1 0 011 1v2"></path></svg>
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Background Color -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-900 mb-2">Background</label>
                        <div class="flex gap-2">
                            <button 
                                @click="backgroundColor = 'white'; fetchLogoPrice()"
                                :class="backgroundColor === 'white' ? 'ring-2 ring-blue-500' : ''"
                                class="flex-1 px-4 py-3 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
                            >
                                WHITE
                            </button>
                            <button 
                                @click="backgroundColor = 'none'; fetchLogoPrice()"
                                :class="backgroundColor === 'none' ? 'ring-2 ring-blue-500' : ''"
                                class="flex-1 px-4 py-3 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
                            >
                                NONE
                            </button>
                            <div class="flex-1 relative">
                                <button
                                    type="button"
                                    @click="selectCustomBackground()"
                                    :class="isCustomBackgroundColor() ? 'ring-2 ring-blue-500' : ''"
                                    class="w-full px-4 py-3 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 flex items-center justify-center gap-2"
                                >
                                    <span class="inline-block w-4 h-4 rounded border border-gray-300" :style="'background-color: ' + backgroundCustomColor"></span>
                                    <span>COLOR</span>
                                </button>
                                <input
                                    type="color"
                                    :value="normalizeHexColor(backgroundCustomColor, '#4F46E5')"
                                    @input="applyCustomBackgroundColor($event.target.value)"
                                    class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                                    aria-label="Pick background color"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Image Size (image content mode only) -->
                    <div x-show="workMode === 'image' && genMode === 'image'" x-transition>
                        <label class="block text-sm font-semibold text-gray-900 mb-2">Image Size</label>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <template x-for="sz in imageSizeOptions()" :key="sz.id">
                                <button type="button" @click="imageSize = sz.id; fetchLogoPrice()"
                                    class="px-2 py-3 bg-white border rounded-lg hover:bg-gray-50 text-center transition-all"
                                    :class="imageSize === sz.id ? 'ring-2 ring-blue-500 border-blue-300' : 'border-gray-300'">
                                    <span class="block text-sm font-semibold text-gray-900" x-text="sz.label"></span>
                                    <span class="block text-xs text-gray-400" x-text="sz.id"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- Mode Info Message -->
                    <div x-show="workMode === 'logo'" class="bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 rounded-lg px-4 py-3">
                        <div class="flex gap-2">
                            <svg class="w-5 h-5 text-indigo-600 dark:text-indigo-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <div>
                                <p class="text-xs font-semibold text-indigo-900 dark:text-indigo-100">Logo Mode (Vector SVG)</p>
                                <p class="text-xs text-indigo-700 dark:text-indigo-300 mt-0.5">Ray generates native SVG. Luna output is vectorized.</p>
                            </div>
                        </div>
                    </div>

                    <div x-show="workMode === 'image'" class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-lg px-4 py-3">
                        <div class="flex gap-2">
                            <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <div>
                                <p class="text-xs font-semibold text-emerald-900 dark:text-emerald-100">Image Mode (Raster PNG)</p>
                                <p class="text-xs text-emerald-700 dark:text-emerald-300 mt-0.5">High-resolution raster images for any use.</p>
                            </div>
                        </div>
                    </div>

                </div>


            </div>

            <!-- Main Canvas Area -->
            <div class="flex-1 overflow-hidden bg-gray-50 dark:bg-gray-900 flex flex-col">
                <!-- Generator Content -->
                <div class="flex-1 overflow-y-auto">
                    <div class="p-8">
                    <!-- Custom Prompt -->
                    <div class="mb-6 rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4">
                        <div class="mb-3 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <label class="block text-sm font-semibold text-gray-900 dark:text-white">Custom Prompt (Optional)</label>
                            <div class="inline-flex flex-wrap gap-2 rounded-lg bg-gray-100 dark:bg-gray-800 p-1">
                                <button 
                                    type="button"
                                    @click="logoMode = 'icon_only'; logoDomain = ''; if (outputFormat === 'vector' && isTextStyle(logoStyle)) logoStyle = 'default'; fetchLogoPrice()"
                                    :class="logoMode === 'icon_only' ? 'bg-violet-600 text-white shadow-sm' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600'"
                                    class="px-3 py-2 rounded-md font-semibold text-xs transition-colors"
                                >
                                    Icon Only
                                </button>
                                <button 
                                    type="button"
                                    x-show="outputFormat !== 'vector'"
                                    x-transition
                                    @click="logoMode = 'icon_text'; fetchLogoPrice()"
                                    :class="logoMode === 'icon_text' ? 'bg-violet-600 text-white shadow-sm' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600'"
                                    class="px-3 py-2 rounded-md font-semibold text-xs transition-colors"
                                >
                                    Icon + Text
                                </button>
                                <button 
                                    type="button"
                                    @click="logoMode = 'text_only'; if (logoStyle !== 'default' && !isTextStyle(logoStyle)) logoStyle = 'modern_sans'; fetchLogoPrice()"
                                    :class="logoMode === 'text_only' ? 'bg-violet-600 text-white shadow-sm' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600'"
                                    class="px-3 py-2 rounded-md font-semibold text-xs transition-colors"
                                >
                                    Text Only
                                </button>
                            </div>
                        </div>
                        <input 
                            type="text" 
                            x-model="logoDomain"
                            @input="fetchLogoPrice()"
                            x-show="logoMode !== 'icon_only'"
                            x-transition
                            placeholder="Logo text, e.g. TechStart, CloudSync, DataFlow"
                            class="mb-3 w-full px-4 py-3 text-base border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-violet-500 focus:border-transparent bg-white dark:bg-gray-800 dark:text-white transition-colors"
                        >
                        <textarea
                            x-model="logoPrompt"
                            @input="fetchLogoPrice()"
                            x-show="logoMode !== 'text_only'"
                            x-transition
                            rows="4"
                            placeholder="Describe your logo in detail: style (modern, vintage, minimalist), mood (professional, playful, elegant), imagery (abstract shapes, tech elements, nature), colors, and any specific elements you want..."
                            class="w-full px-4 py-3.5 text-base border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-violet-500 focus:border-transparent resize-y bg-white dark:bg-gray-800 dark:text-white leading-relaxed"
                        ></textarea>
                        <p x-show="logoMode !== 'text_only'" x-transition class="mt-2 text-xs text-gray-500 dark:text-gray-400">Be specific about style, colors, and elements for best results.</p>
                    </div>

                    <!-- Error Display -->
                    <div x-show="error" x-cloak class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                        <div class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-red-600 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                            </svg>
                            <div class="flex-1">
                                <h4 class="font-semibold text-red-900">Error</h4>
                                <p class="text-sm text-red-700 mt-1" x-text="error"></p>
                            </div>
                            <button @click="error = null" class="text-red-600 hover:text-red-800">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Results Grid -->
                    <div x-show="logoBatches.length > 0" class="space-y-8">
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Generated Logos</h2>
                        
                        <template x-for="(batch, batchIndex) in logoBatches" :key="batch.id">
                            <div class="space-y-4">
                                <!-- Batch Divider (not shown for first batch) -->
                                <div x-show="batchIndex > 0" class="flex items-center gap-4 py-4">
                                    <div class="flex-1 h-px bg-gradient-to-r from-transparent via-gray-300 dark:via-gray-700 to-transparent"></div>
                                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400" x-text="'Previous generation · ' + new Date(batch.timestamp).toLocaleString()"></div>
                                    <div class="flex-1 h-px bg-gradient-to-r from-transparent via-gray-300 dark:via-gray-700 to-transparent"></div>
                                </div>

                                <!-- Images Grid -->
                                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-6">
                                    <!-- Loading Placeholders -->
                                    <template x-if="batch.loading">
                                        <template x-for="n in batch.expectedCount" :key="n">
                                            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                                                <div class="aspect-square bg-gray-50 dark:bg-gray-700 relative flex items-center justify-center">
                                                    <div class="text-center">
                                                        <svg class="animate-spin h-12 w-12 mx-auto text-violet-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                        </svg>
                                                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-3">Generating...</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </template>
                                    
                                    <!-- Generated Images -->
                                    <template x-for="(image, imageIndex) in batch.images" :key="image.key || image.editUrl || image.url || imageIndex">
                                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden hover:shadow-lg transition-shadow">
                                            <!-- Failed Image Placeholder -->
                                            <template x-if="image.failed">
                                                <div class="aspect-square bg-gray-50 dark:bg-gray-700 relative flex items-center justify-center">
                                                    <div class="text-center">
                                                        <svg class="h-16 w-16 mx-auto text-red-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                        </svg>
                                                        <p class="text-sm text-red-600 dark:text-red-400 mt-3 px-4" x-text="image.error || 'Failed to generate'"></p>
                                                    </div>
                                                </div>
                                            </template>
                                            <!-- Image -->
                                            <div x-show="!image.failed" class="aspect-square bg-white dark:bg-gray-100 relative group cursor-pointer" @click="zoomImage(image.displayUrl || image.url)">
                                                <img :src="image.displayUrl || image.url" :alt="'Logo ' + (imageIndex + 1)" class="w-full h-full object-contain p-4" loading="lazy">
                                                
                                                <!-- Metadata Tags Overlay -->
                                                <div class="absolute top-2 left-2 flex flex-wrap gap-1.5">
                                                    <span class="px-2 py-1 bg-blue-600 text-white text-xs font-semibold rounded" x-text="image.metadata?.model || batch.metadata?.model || 'Luna'"></span>
                                                    <span class="px-2 py-1 bg-gray-800 text-white text-xs font-medium rounded" x-text="image.metadata?.resolution || batch.metadata?.resolution || '512x512'"></span>
                                                </div>
                                                <div class="absolute top-2 right-2 flex flex-wrap gap-1.5 justify-end">
                                                    <span class="px-2 py-1 bg-violet-600 text-white text-xs font-medium rounded capitalize" x-text="image.metadata?.style || batch.metadata?.style || 'professional'"></span>
                                                    <span class="px-2 py-1 bg-emerald-600 text-white text-xs font-semibold rounded" x-text="'$' + (image.metadata?.price || batch.metadata?.price || '0.00')"></span>
                                                </div>
                                            </div>

                                            <!-- Actions -->
                                    <div x-show="!image.failed" class="p-4">
                                        <div class="grid grid-cols-1 gap-2">
                                            <button 
                                                @click="saveLogo(image.editUrl || image.url)"
                                                class="px-3 py-2 bg-violet-600 hover:bg-violet-700 text-white text-sm font-medium rounded-lg transition-colors"
                                            >
                                                Save
                                            </button>
                                            <button
                                                x-show="!image.isVector"
                                                @click.stop="upscaleGeneratedImage(batchIndex, imageIndex)"
                                                :disabled="image.upscaling"
                                                class="px-3 py-2 bg-sky-600 hover:bg-sky-700 disabled:bg-sky-400 disabled:cursor-wait text-white text-sm font-medium rounded-lg transition-colors"
                                                x-text="image.upscaling ? 'Upsizing...' : 'Upsize ($' + upscalePrice.toFixed(2) + ')'"
                                            ></button>
                                            <p x-show="image.upscaleError" x-text="image.upscaleError" class="text-xs text-red-600 dark:text-red-400"></p>
                                        </div>
                                    </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Similar Ideas Section -->
                    <div x-show="similarIdeas.length > 0" class="mt-12 space-y-6">
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Similar Ideas</h2>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-6">
                            <template x-for="idea in similarIdeas" :key="idea.id">
                                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden hover:shadow-lg transition-shadow">
                                    <div class="aspect-square bg-gray-100 relative cursor-pointer" @click="zoomImage(idea.prompt_outputs[0].url)">
                                        <img :src="idea.prompt_outputs[0].url" :alt="idea.query" class="w-full h-full object-contain p-4" loading="lazy">
                                    </div>
                                    <div class="p-4">
                                        <p class="text-sm text-gray-700 line-clamp-2" x-text="idea.query"></p>
                                        <button 
                                            @click="loadFromSimilar(idea)"
                                            class="mt-3 w-full px-3 py-2 bg-violet-600 hover:bg-violet-700 text-white text-sm font-medium rounded-lg transition-colors"
                                        >
                                            Load This
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Empty State -->
                    <div x-show="logoBatches.length === 0" class="flex flex-col items-center justify-center py-24 text-center">
                        <div class="p-6 bg-violet-100 rounded-full mb-6">
                            <svg class="w-16 h-16 text-violet-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 008 10.172V5L7 4z"/>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-900 mb-2">Ready to Create</h3>
                        <p class="text-gray-600 max-w-md">Configure your logo settings in the sidebar and click Generate to create stunning AI-powered logos.</p>
                    </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Style Selection Modal -->
        <div x-show="showStyleModal" x-cloak x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4" @click.self="showStyleModal = false" @keydown.escape.window="showStyleModal = false">
            <div class="relative flex max-h-[calc(100vh-2rem)] w-full max-w-lg flex-col overflow-hidden rounded-2xl bg-white shadow-2xl" @click.stop
                x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100">
                <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200">
                    <h3 class="text-base font-semibold text-gray-900">Choose Style</h3>
                    <button @click="showStyleModal = false" class="p-1 rounded-lg hover:bg-gray-100 transition">
                        <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <div class="overflow-y-auto p-5">
                    <div class="mb-5 grid grid-cols-2 rounded-lg border border-gray-200 bg-gray-50 p-1">
                        <button type="button" @click="styleModalTab = 'style'"
                            class="rounded-md px-3 py-2 text-xs font-bold uppercase tracking-wider transition"
                            :class="styleModalTab === 'style' ? 'bg-white text-violet-700 shadow-sm' : 'text-gray-500 hover:text-gray-700'">
                            Style
                        </button>
                        <button type="button" @click="styleModalTab = 'theme'"
                            class="rounded-md px-3 py-2 text-xs font-bold uppercase tracking-wider transition"
                            :class="styleModalTab === 'theme' ? 'bg-white text-violet-700 shadow-sm' : 'text-gray-500 hover:text-gray-700'">
                            Theme
                        </button>
                    </div>

                    <!-- DALL-E styles -->
                    <div x-show="styleModalTab === 'style' && selectedModel === 'dalle'">
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            <button type="button" @click="selectStyle('default')"
                                class="group rounded-xl border-2 p-3 transition-all text-center"
                                :class="logoStyle === 'default' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'default' ? 'text-blue-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4.5 7.5h15m-15 4.5h15m-15 4.5h15"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'default' ? 'text-blue-700' : 'text-gray-600'">Default</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">No style bias</div>
                            </button>
                            <button type="button" @click="selectStyle('professional')"
                                class="group rounded-xl border-2 p-3 transition-all text-center"
                                :class="logoStyle === 'professional' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'professional' ? 'text-blue-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'professional' ? 'text-blue-700' : 'text-gray-600'">Professional</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">Clean & modern</div>
                            </button>
                            <button type="button" @click="selectStyle('fantasy')"
                                class="group rounded-xl border-2 p-3 transition-all text-center"
                                :class="logoStyle === 'fantasy' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'fantasy' ? 'text-blue-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'fantasy' ? 'text-blue-700' : 'text-gray-600'">Fantasy</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">Magical & ornate</div>
                            </button>
                            <button type="button" @click="selectStyle('future')"
                                class="group rounded-xl border-2 p-3 transition-all text-center"
                                :class="logoStyle === 'future' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'future' ? 'text-blue-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'future' ? 'text-blue-700' : 'text-gray-600'">Future</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">Techy & sci-fi</div>
                            </button>
                            <button type="button" @click="selectStyle('retro')" x-show="logoMode !== 'icon_only'"
                                class="group rounded-xl border-2 p-3 transition-all text-center"
                                :class="logoStyle === 'retro' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'retro' ? 'text-blue-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'retro' ? 'text-blue-700' : 'text-gray-600'">Retro</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">Vintage & classic</div>
                            </button>
                            <button type="button" @click="selectStyle('greetingcard')"
                                class="group rounded-xl border-2 p-3 transition-all text-center"
                                :class="logoStyle === 'greetingcard' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'greetingcard' ? 'text-blue-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'greetingcard' ? 'text-blue-700' : 'text-gray-600'">Watercolor</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">Watercolor & gouache</div>
                            </button>
                            <button type="button" @click="selectStyle('photorealistic')"
                                class="group rounded-xl border-2 p-3 transition-all text-center"
                                :class="logoStyle === 'photorealistic' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'photorealistic' ? 'text-blue-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'photorealistic' ? 'text-blue-700' : 'text-gray-600'">Photorealistic</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">Lifelike & detailed</div>
                            </button>
                        </div>
                        <div class="mt-5 mb-4">
                            <div class="w-full h-px bg-gradient-to-r from-transparent via-violet-400/70 to-transparent"></div>
                            <div class="text-center -mt-3">
                                <span class="inline-flex px-3 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider bg-white text-violet-600">Dalle3 specific styles</span>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            <button type="button" @click="selectStyle('chrome')" x-show="logoMode !== 'icon_only'"
                                class="group rounded-xl border-2 p-2 transition-all text-center"
                                :class="logoStyle === 'chrome' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                                <div class="aspect-square rounded-lg overflow-hidden bg-gray-100 mb-2">
                                    <img src="/images/chrome-preview.svg" alt="Chrome style" class="w-full h-full object-cover" />
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'chrome' ? 'text-blue-700' : 'text-gray-600'">Chrome (Chome)</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">3D metallic render</div>
                                <span class="inline-block mt-1 px-1.5 py-0.5 rounded text-[9px] font-semibold bg-amber-100 text-amber-700">Icon Only</span>
                            </button>
                            <button type="button" @click="selectStyle('dotmatrix')" x-show="logoMode !== 'icon_only'"
                                class="group rounded-xl border-2 p-3 transition-all text-center"
                                :class="logoStyle === 'dotmatrix' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'dotmatrix' ? 'text-blue-600' : 'text-gray-400'" fill="currentColor" viewBox="0 0 24 24"><circle cx="6" cy="6" r="1.5"/><circle cx="12" cy="6" r="1.5"/><circle cx="18" cy="6" r="1.5"/><circle cx="6" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="18" cy="12" r="1.5"/><circle cx="6" cy="18" r="1.5"/><circle cx="12" cy="18" r="1.5"/><circle cx="18" cy="18" r="1.5"/></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'dotmatrix' ? 'text-blue-700' : 'text-gray-600'">Dot Matrix</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">Stipple art</div>
                                <span class="inline-block mt-1 px-1.5 py-0.5 rounded text-[9px] font-semibold bg-amber-100 text-amber-700">Icon Only</span>
                            </button>
                            <button type="button" @click="selectStyle('8bit')" x-show="logoMode !== 'icon_only'"
                                class="group rounded-xl border-2 p-3 transition-all text-center"
                                :class="logoStyle === '8bit' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === '8bit' ? 'text-blue-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === '8bit' ? 'text-blue-700' : 'text-gray-600'">8-Bit</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">Fantasy RPG</div>
                            </button>
                        </div>
                    </div>

                    <!-- Flux/Recraft styles -->
                    <div x-show="styleModalTab === 'style' && selectedModel !== 'dalle'" class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        <button type="button" @click="selectStyle('default')"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'default' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                <svg class="w-6 h-6" :class="logoStyle === 'default' ? 'text-blue-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4.5 7.5h15m-15 4.5h15m-15 4.5h15"></path></svg>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'default' ? 'text-blue-700' : 'text-gray-600'">Default</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">No style bias</div>
                        </button>
                        <!-- Image styles: shown in raster/image mode -->
                        <button type="button" @click="selectStyle('professional')" x-show="outputFormat !== 'vector'"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'professional' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                <svg class="w-6 h-6" :class="logoStyle === 'professional' ? 'text-blue-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'professional' ? 'text-blue-700' : 'text-gray-600'">Professional</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Clean & modern</div>
                        </button>
                        <button type="button" @click="selectStyle('fantasy')" x-show="outputFormat !== 'vector'"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'fantasy' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                <svg class="w-6 h-6" :class="logoStyle === 'fantasy' ? 'text-blue-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"></path></svg>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'fantasy' ? 'text-blue-700' : 'text-gray-600'">Fantasy</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Magical & ornate</div>
                        </button>
                        <button type="button" @click="selectStyle('future')" x-show="outputFormat !== 'vector'"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'future' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                <svg class="w-6 h-6" :class="logoStyle === 'future' ? 'text-blue-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"></path></svg>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'future' ? 'text-blue-700' : 'text-gray-600'">Future</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Techy & sci-fi</div>
                        </button>
                        <button type="button" @click="selectStyle('retro')" x-show="outputFormat !== 'vector'"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'retro' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                <svg class="w-6 h-6" :class="logoStyle === 'retro' ? 'text-blue-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'retro' ? 'text-blue-700' : 'text-gray-600'">Retro</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Vintage & classic</div>
                        </button>
                        <button type="button" @click="selectStyle('greetingcard')" x-show="outputFormat !== 'vector'"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'greetingcard' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                <svg class="w-6 h-6" :class="logoStyle === 'greetingcard' ? 'text-blue-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"></path></svg>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'greetingcard' ? 'text-blue-700' : 'text-gray-600'">Watercolor</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Watercolor & gouache</div>
                        </button>
                        <button type="button" @click="selectStyle('photorealistic')" x-show="outputFormat !== 'vector'"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'photorealistic' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                <svg class="w-6 h-6" :class="logoStyle === 'photorealistic' ? 'text-blue-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z"></path></svg>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'photorealistic' ? 'text-blue-700' : 'text-gray-600'">Photorealistic</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Lifelike & detailed</div>
                        </button>
                        <!-- Vector styles: shown in vector/logo mode -->
                        <button type="button" @click="selectStyle('minimal_geometric')" x-show="outputFormat === 'vector' && logoMode !== 'text_only'"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'minimal_geometric' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-gray-100 overflow-hidden">
                                <template x-if="isSampledVectorMode()">
                                    <img :src="getVectorSampleUrl('minimal_geometric')" alt="Minimal Geometric sample" class="style-sample-image w-full h-full object-cover" loading="lazy" />
                                </template>
                                <template x-if="!isSampledVectorMode()">
                                    <div class="w-full h-full flex items-center justify-center">
                                        <svg class="w-6 h-6" :class="logoStyle === 'minimal_geometric' ? 'text-blue-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z"></path></svg>
                                    </div>
                                </template>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'minimal_geometric' ? 'text-blue-700' : 'text-gray-600'">Minimal Geometric</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Clean shapes</div>
                        </button>
                        <button type="button" @click="selectStyle('abstract')" x-show="outputFormat === 'vector' && logoMode !== 'text_only'"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'abstract' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-gray-100 overflow-hidden">
                                <template x-if="isSampledVectorMode()">
                                    <img :src="getVectorSampleUrl('abstract')" alt="Abstract sample" class="style-sample-image w-full h-full object-cover" loading="lazy" />
                                </template>
                                <template x-if="!isSampledVectorMode()">
                                    <div class="w-full h-full flex items-center justify-center">
                                        <svg class="w-6 h-6" :class="logoStyle === 'abstract' ? 'text-blue-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"></path></svg>
                                    </div>
                                </template>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'abstract' ? 'text-blue-700' : 'text-gray-600'">Abstract</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Dynamic shapes</div>
                        </button>
                        <button type="button" @click="selectStyle('monoline')" x-show="outputFormat === 'vector' && logoMode !== 'text_only'"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'monoline' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-gray-100 overflow-hidden">
                                <template x-if="isSampledVectorMode()">
                                    <img :src="getVectorSampleUrl('monoline')" alt="Monoline sample" class="style-sample-image w-full h-full object-cover" loading="lazy" />
                                </template>
                                <template x-if="!isSampledVectorMode()">
                                    <div class="w-full h-full flex items-center justify-center">
                                        <svg class="w-6 h-6" :class="logoStyle === 'monoline' ? 'text-blue-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 12 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    </div>
                                </template>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'monoline' ? 'text-blue-700' : 'text-gray-600'">Monoline</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Continuous line</div>
                        </button>
                        <button type="button" @click="selectStyle('negative_space')" x-show="outputFormat === 'vector' && logoMode !== 'text_only'"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'negative_space' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-gray-100 overflow-hidden">
                                <template x-if="isSampledVectorMode()">
                                    <img :src="getVectorSampleUrl('negative_space')" alt="Negative Space sample" class="style-sample-image w-full h-full object-cover" loading="lazy" />
                                </template>
                                <template x-if="!isSampledVectorMode()">
                                    <div class="w-full h-full flex items-center justify-center">
                                        <svg class="w-6 h-6" :class="logoStyle === 'negative_space' ? 'text-blue-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007zM8.625 10.5a.375.375 0 11-.75 0 .375.375 0 01.75 0zm7.5 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"></path></svg>
                                    </div>
                                </template>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'negative_space' ? 'text-blue-700' : 'text-gray-600'">Negative Space</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Hidden symbol</div>
                        </button>
                        <button type="button" @click="selectStyle('tech_gradient')" x-show="outputFormat === 'vector' && logoMode !== 'text_only'"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'tech_gradient' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-gray-100 overflow-hidden">
                                <template x-if="isSampledVectorMode()">
                                    <img :src="getVectorSampleUrl('tech_gradient')" alt="Tech Gradient sample" class="style-sample-image w-full h-full object-cover" loading="lazy" />
                                </template>
                                <template x-if="!isSampledVectorMode()">
                                    <div class="w-full h-full flex items-center justify-center">
                                        <svg class="w-6 h-6" :class="logoStyle === 'tech_gradient' ? 'text-blue-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"></path></svg>
                                    </div>
                                </template>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'tech_gradient' ? 'text-blue-700' : 'text-gray-600'">Tech Gradient</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Futuristic</div>
                        </button>
                        <button type="button" @click="selectStyle('modern_sans')" x-show="outputFormat === 'vector' && logoMode === 'text_only'"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'modern_sans' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                <span class="text-lg font-semibold tracking-tight" :class="logoStyle === 'modern_sans' ? 'text-blue-700' : 'text-gray-700'">Aa</span>
                            </div>
                            <div class="text-xs font-semibold" :class="logoStyle === 'modern_sans' ? 'text-blue-700' : 'text-gray-600'">Modern Sans</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Clean sans-serif</div>
                        </button>
                        <button type="button" @click="selectStyle('bold_geometric')" x-show="outputFormat === 'vector' && logoMode === 'text_only'"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'bold_geometric' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                <span class="text-xl font-black tracking-tight" :class="logoStyle === 'bold_geometric' ? 'text-blue-700' : 'text-gray-700'">Aa</span>
                            </div>
                            <div class="text-xs font-semibold" :class="logoStyle === 'bold_geometric' ? 'text-blue-700' : 'text-gray-600'">Bold Geometric</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Strong structure</div>
                        </button>
                        <button type="button" @click="selectStyle('elegant_serif')" x-show="outputFormat === 'vector' && logoMode === 'text_only'"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'elegant_serif' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                <span class="text-xl font-serif tracking-tight" :class="logoStyle === 'elegant_serif' ? 'text-blue-700' : 'text-gray-700'">Aa</span>
                            </div>
                            <div class="text-xs font-semibold" :class="logoStyle === 'elegant_serif' ? 'text-blue-700' : 'text-gray-600'">Elegant Serif</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Refined strokes</div>
                        </button>
                        <button type="button" @click="selectStyle('script_signature')" x-show="outputFormat === 'vector' && logoMode === 'text_only'"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'script_signature' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                <span class="text-xl italic tracking-tight" :class="logoStyle === 'script_signature' ? 'text-blue-700' : 'text-gray-700'">Aa</span>
                            </div>
                            <div class="text-xs font-semibold" :class="logoStyle === 'script_signature' ? 'text-blue-700' : 'text-gray-600'">Script Signature</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Flowing curves</div>
                        </button>
                        <button type="button" @click="selectStyle('tech_mono')" x-show="outputFormat === 'vector' && logoMode === 'text_only'"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'tech_mono' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                <span class="text-lg font-mono tracking-tight" :class="logoStyle === 'tech_mono' ? 'text-blue-700' : 'text-gray-700'">Aa</span>
                            </div>
                            <div class="text-xs font-semibold" :class="logoStyle === 'tech_mono' ? 'text-blue-700' : 'text-gray-600'">Tech Mono</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Precision spacing</div>
                        </button>
                        <button type="button" @click="selectStyle('minimal_light')" x-show="outputFormat === 'vector' && logoMode === 'text_only'"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'minimal_light' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-full h-24 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                <span class="text-lg font-light tracking-tight" :class="logoStyle === 'minimal_light' ? 'text-blue-700' : 'text-gray-700'">Aa</span>
                            </div>
                            <div class="text-xs font-semibold" :class="logoStyle === 'minimal_light' ? 'text-blue-700' : 'text-gray-600'">Minimal Light</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Thin clean letterforms</div>
                        </button>
                    </div>

                    <div x-show="styleModalTab === 'theme'" class="space-y-3">
                        <button type="button" @click="selectTheme('')"
                            class="w-full rounded-xl border-2 p-4 text-left transition-all"
                            :class="logoTheme === '' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="text-sm font-semibold" :class="logoTheme === '' ? 'text-blue-700' : 'text-gray-800'">No Theme</div>
                            <div class="mt-0.5 text-xs text-gray-500">Use only the selected style and description.</div>
                        </button>
                        <button type="button" @click="selectTheme('real_estate')"
                            class="group w-full rounded-xl border-2 p-3 text-left transition-all"
                            :class="logoTheme === 'real_estate' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="flex gap-3">
                                <div class="h-20 w-28 flex-shrink-0 rounded-lg bg-gray-100 flex items-center justify-center overflow-hidden">
                                    <svg class="w-24 h-14" viewBox="0 0 112 64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                        <path d="M10 39C31 28 62 57 102 34" stroke="#1d4ed8" stroke-width="8" stroke-linecap="round"/>
                                        <path d="M14 45C39 37 55 67 94 55" stroke="#38bdf8" stroke-width="4" stroke-linecap="round"/>
                                        <path d="M30 35L51 13L72 35H61L51 24L41 35H30Z" fill="#1e3a8a"/>
                                        <path d="M72 32L88 17L104 32H95L88 25L81 32H72Z" fill="#f97316"/>
                                        <path d="M54 12H61V25H54V12Z" fill="#2563eb"/>
                                    </svg>
                                </div>
                                <div class="min-w-0 py-1">
                                    <div class="text-sm font-semibold" :class="logoTheme === 'real_estate' ? 'text-blue-700' : 'text-gray-800'">Real Estate</div>
                                    <div class="mt-1 text-xs text-gray-500">Architectural cues, rooflines, buildings, and clean property-brand geometry.</div>
                                </div>
                            </div>
                        </button>
                        <button type="button" @click="selectTheme('nature')"
                            class="group w-full rounded-xl border-2 p-3 text-left transition-all"
                            :class="logoTheme === 'nature' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="flex gap-3">
                                <div class="h-20 w-28 flex-shrink-0 rounded-lg bg-gray-100 overflow-hidden">
                                    <img src="/images/ray_vector_samples/ray_evergreen_silhouette_vector.svg" alt="Nature sample" class="style-sample-image h-full w-full object-cover" loading="lazy" />
                                </div>
                                <div class="min-w-0 py-1">
                                    <div class="text-sm font-semibold" :class="logoTheme === 'nature' ? 'text-blue-700' : 'text-gray-800'">Nature</div>
                                    <div class="mt-1 text-xs text-gray-500">Outdoor cues, trees, leaves, landforms, and clean organic silhouettes.</div>
                                </div>
                            </div>
                        </button>
                        <button type="button" @click="selectTheme('fantasy')"
                            class="group w-full rounded-xl border-2 p-3 text-left transition-all"
                            :class="logoTheme === 'fantasy' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="flex gap-3">
                                <div class="h-20 w-28 flex-shrink-0 rounded-lg bg-gray-100 flex items-center justify-center overflow-hidden">
                                    <svg class="h-full w-full" viewBox="0 0 112 80" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                        <rect width="112" height="80" rx="8" fill="#1e1b4b"/>
                                        <path d="M14 62L31 36L42 50L54 25L75 62H14Z" fill="#312e81"/>
                                        <path d="M57 62L73 32L96 62H57Z" fill="#4338ca"/>
                                        <path d="M58 14L63 25L75 26L66 34L69 46L58 39L47 46L50 34L41 26L53 25L58 14Z" fill="#facc15"/>
                                        <path d="M26 62C34 49 42 43 50 44C61 46 65 58 78 62H26Z" fill="#7c3aed"/>
                                        <path d="M25 63H88" stroke="#c4b5fd" stroke-width="4" stroke-linecap="round"/>
                                        <path d="M35 53L41 48L47 53M69 53L75 48L81 53" stroke="#fef3c7" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                                <div class="min-w-0 py-1">
                                    <div class="text-sm font-semibold" :class="logoTheme === 'fantasy' ? 'text-blue-700' : 'text-gray-800'">Fantasy</div>
                                    <div class="mt-1 text-xs text-gray-500">Magic, quests, creatures, castles, weapons, and dramatic adventure silhouettes.</div>
                                </div>
                            </div>
                        </button>
                        <button type="button" @click="selectTheme('technology')"
                            class="group w-full rounded-xl border-2 p-3 text-left transition-all"
                            :class="logoTheme === 'technology' ? 'border-blue-500 ring-2 ring-blue-200 bg-blue-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="flex gap-3">
                                <div class="h-20 w-28 flex-shrink-0 rounded-lg bg-slate-950 flex items-center justify-center overflow-hidden">
                                    <svg class="h-full w-full" viewBox="0 0 112 80" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                        <rect width="112" height="80" rx="8" fill="#0f172a"/>
                                        <path d="M18 23H31L39 31M18 57H31L39 49M94 23H81L73 31M94 57H81L73 49M56 12V22M56 58V68" stroke="#38bdf8" stroke-width="3" stroke-linecap="round"/>
                                        <circle cx="17" cy="23" r="4" fill="#22d3ee"/>
                                        <circle cx="17" cy="57" r="4" fill="#8b5cf6"/>
                                        <circle cx="95" cy="23" r="4" fill="#8b5cf6"/>
                                        <circle cx="95" cy="57" r="4" fill="#22d3ee"/>
                                        <circle cx="56" cy="11" r="3" fill="#a78bfa"/>
                                        <circle cx="56" cy="69" r="3" fill="#22d3ee"/>
                                        <rect x="37" y="21" width="38" height="38" rx="9" fill="#2563eb"/>
                                        <path d="M48 45L42 40L48 35M64 35L70 40L64 45M59 31L53 49" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                                <div class="min-w-0 py-1">
                                    <div class="text-sm font-semibold" :class="logoTheme === 'technology' ? 'text-blue-700' : 'text-gray-800'">Technology</div>
                                    <div class="mt-1 text-xs text-gray-500">Software, AI, hardware, networks, cybersecurity, and clean digital geometry.</div>
                                </div>
                            </div>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Zoom Modal -->
        <div x-show="zoomImageUrl" x-cloak class="fixed inset-0 bg-black bg-opacity-90 flex items-center justify-center z-50 p-4" @click="zoomImageUrl = null">
            <div class="max-w-6xl w-full" @click.stop>
                <img :src="zoomImageUrl" alt="Zoomed logo" class="w-full h-auto rounded-lg shadow-2xl">
            </div>
        </div>

        <!-- Login Gate -->
    </div>

    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    @include('logos.partials.generator-script')
    @else
    <div class="h-screen flex items-center justify-center">
        <div class="text-center">
            <svg class="mx-auto h-16 w-16 text-violet-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
            </svg>
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Login Required</h3>
            <p class="text-sm text-gray-500 mb-6">Please log in to use the AI Logo Lab</p>
            <a href="/admin/login" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-violet-600 hover:bg-violet-700 text-white font-semibold transition">Log In</a>
        </div>
    </div>
    @endif
</body>
</html>
