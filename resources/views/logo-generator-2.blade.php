<!DOCTYPE html>
<html lang="en">
<head>
    <script>if(localStorage.getItem('darkMode')==='true')document.documentElement.classList.add('dark');</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AI Logo Lab - Toolbase</title>
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
    <div x-data="logoGenerator()" class="min-h-screen flex flex-col">
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

                    <!-- Logo Mode -->
                    <div class="space-y-3">
                        <label class="block text-sm font-semibold text-gray-900 dark:text-white">Logo Mode</label>
                        <div class="grid grid-cols-3 gap-2">
                            <button 
                                type="button"
                                @click="logoMode = 'icon_only'; logoDomain = ''; if (outputFormat === 'vector' && isTextStyle(logoStyle)) logoStyle = 'default'; fetchLogoPrice()"
                                :class="logoMode === 'icon_only' ? 'bg-violet-600 text-white border-violet-600' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600'"
                                class="px-2 py-2.5 border rounded-lg font-medium text-xs transition-colors"
                            >
                                Icon Only
                            </button>
                            <button 
                                type="button"
                                @click="if (workMode !== 'logo') { logoMode = 'icon_text'; fetchLogoPrice(); }"
                                :disabled="workMode === 'logo'"
                                :class="[
                                    logoMode === 'icon_text' ? 'bg-violet-600 text-white border-violet-600' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600',
                                    workMode === 'logo' ? 'opacity-50 cursor-not-allowed' : ''
                                ]"
                                class="px-2 py-2.5 border rounded-lg font-medium text-xs transition-colors"
                            >
                                Icon + Text
                            </button>
                            <button 
                                type="button"
                                @click="logoMode = 'text_only'; if (logoStyle !== 'default' && !isTextStyle(logoStyle)) logoStyle = 'modern_sans'; fetchLogoPrice()"
                                :class="logoMode === 'text_only' ? 'bg-violet-600 text-white border-violet-600' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600'"
                                class="px-2 py-2.5 border rounded-lg font-medium text-xs transition-colors"
                            >
                                Text Only
                            </button>
                        </div>
                        <p x-show="workMode === 'logo'" x-transition class="text-xs text-amber-600 dark:text-amber-400">
                            For vector generation, logo and text should be generated separately to ensure professional quality and positioning control.
                        </p>
                        <input 
                            type="text" 
                            x-model="logoDomain"
                            @input="fetchLogoPrice()"
                            x-show="logoMode !== 'icon_only'"
                            placeholder="e.g., TechStart, CloudSync, DataFlow, etc."
                            class="w-full px-4 py-3.5 text-base border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-violet-500 focus:border-transparent bg-white dark:bg-gray-800 dark:text-white transition-colors"
                        >
                    </div>

                    <!-- Detail Level -->
                    <div>
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
                    <div>
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
                        <label class="block text-sm font-semibold text-gray-900 dark:text-white mb-2">Custom Prompt (Optional)</label>
                        <textarea
                            x-model="logoPrompt"
                            @input="fetchLogoPrice()"
                            rows="4"
                            placeholder="Describe your logo in detail: style (modern, vintage, minimalist), mood (professional, playful, elegant), imagery (abstract shapes, tech elements, nature), colors, and any specific elements you want..."
                            class="w-full px-4 py-3.5 text-base border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-violet-500 focus:border-transparent resize-y bg-white dark:bg-gray-800 dark:text-white leading-relaxed"
                        ></textarea>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Be specific about style, colors, and elements for best results.</p>
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
            <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-2xl overflow-hidden" @click.stop
                x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100">
                <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200">
                    <h3 class="text-base font-semibold text-gray-900">Choose Style</h3>
                    <button @click="showStyleModal = false" class="p-1 rounded-lg hover:bg-gray-100 transition">
                        <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <div class="p-5">
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
    <script>
        function logoGenerator() {
            return {
                // Model and configuration
                selectedModel: 'flux',
                logoCount: 2,
                logoDomain: '',
                logoPrompt: '',
                logoStyle: 'default',
                logoTheme: '',
                logoColorPalette: 'none',
                showGenerationSettings: false,
                showLeftPanel: true,
                logoMode: 'icon_only',
                styleModalTab: 'style',
                
                // Tab state
                activeTab: 'generator',
                
                // Legacy vector state retained for generated SVG processing.
                editorSvgUrl: null,
                editorSvgElement: null,
                selectedElements: [],
                selectedElementColor: '#000000',
                editorText: '',
                editorFontSize: 48,
                editorFontBold: true,
                editorFontItalic: false,
                editorTextUseVector: false,
                selectedTextContent: '',
                selectedTextFontFamily: 'Arial',
                selectedTextFontSize: 48,
                selectedTextBold: false,
                selectedTextItalic: false,
                textFontOptions: [
                    'Inter', 'Poppins', 'Montserrat', 'Roboto', 'Open Sans', 'Lato', 'Nunito', 'DM Sans', 'Work Sans', 'Source Sans 3',
                    'Playfair Display', 'Merriweather', 'Libre Baskerville', 'Cormorant Garamond', 'Cinzel', 'Bitter', 'Arvo',
                    'Oswald', 'Anton', 'Bebas Neue', 'Bungee', 'Space Grotesk', 'Exo 2', 'Fira Sans', 'IBM Plex Sans', 'Rubik',
                    'Raleway', 'Josefin Sans', 'Cabin', 'Comfortaa', 'Dancing Script', 'Lobster', 'Abril Fatface', 'Macondo',
                    'Arial', 'Helvetica', 'Times New Roman', 'Georgia', 'Verdana', 'Trebuchet MS', 'Courier New', 'Impact'
                ],
                editingTextElement: null,
                // Selection box for marquee selection
                isSelecting: false,
                selectionStartX: 0,
                selectionStartY: 0,
                selectionEndX: 0,
                selectionEndY: 0,
                selectionRectElement: null,
                hoverMenu: { visible: false, x: 0, y: 0 },
                replaceTargetElement: null,
                editorFontFamily: 'Arial',
                editorTextColor: '#000000',
                editorZoom: 1,
                canvasSize: 'default',
                svgLayers: [],
                editMode: false,
                editGroupMode: false,
                editingGroup: null,
                showImportModal: false,
                showColorModal: false,
                showTextModal: false,
                showShapeModal: false,
                showLayersModal: false,
                importModalTab: 'session',
                userLogos: [],
                loadingUserLogos: false,
                undoStack: [],
                showSaveStateModal: false,
                saveStateName: '',
                selectedStateToOverwrite: null,
                editorStates: [],
                editorShapeType: 'rectangle',
                editorShapeSize: 120,
                editorShapeFill: '#38BDF8',
                editorShapeStroke: '#0F172A',
                editorShapeStrokeWidth: 2,
                logoCustomColors: ['#1e3a5f', '#d4af37', '#333333'],
                backgroundColor: 'white',
                backgroundCustomColor: '#4F46E5',
                shapeContainer: '',
                detailLevel: 'medium',
                proMode: true,
                proSize: '512',
                workMode: 'logo', // 'image' or 'logo'
                outputFormat: 'vector',
                imageFormat: 'png',
                genMode: 'logo', // 'logo' or 'image' content (image/raster workMode only)
                imageSize: '1:1', // '1:1' | '16:9' | '9:16'
                seed: null,

                // State
                logoBatches: [],
                generating: false,
                logoPrice: 0,
                upscalePrice: @js((float) \App\Models\AiLogoPrice::estimateUpscaleCost()['cost_per_image']),
                creditBalance: @js((float) ($logoUser->credit_balance ?? 0)),
                error: null,
                showStyleModal: false,
                zoomImageUrl: null,
                similarIdeas: [],

                // Palette management
                canManagePalettes: @js((bool) $logoUser),
                savedPalettes: [],
                savedPaletteName: '',
                paletteSaving: false,
                paletteLoading: false,
                paletteError: null,
                paletteSuccess: null,

                // Persistent generator settings
                canSaveSettings: @js((bool) $logoUser),
                savedLogoSettings: @js($logoGeneratorSettings ?? []),
                settingsSaving: false,
                settingsStatus: null,
                settingsError: null,

                // Available options
                colorPalettes: [
                    { id: 'fire',   name: 'Fire',   colors: ['#D00000', '#E85D04', '#FFBA08'] },
                    { id: 'pastel', name: 'Pastel', colors: ['#FFB5A7', '#FCD5CE', '#A2D2FF'] },
                    { id: 'royal',  name: 'Royal',  colors: ['#7B2CBF', '#C77DFF', '#E0AAFF'] },
                    { id: 'ice',    name: 'Ice',    colors: ['#0077B6', '#00B4D8', '#90E0EF'] },
                ],

                normalizeHexColor(value, fallback = '#ffffff') {
                    const v = String(value || '').trim();
                    return /^#[0-9a-fA-F]{6}$/.test(v) ? v : fallback;
                },

                normalizePaletteColors(colors) {
                    if (!Array.isArray(colors)) return [];
                    return colors
                        .map((color) => this.normalizeHexColor(color, ''))
                        .filter((color) => /^#[0-9a-fA-F]{6}$/.test(color))
                        .map((color) => color.toUpperCase())
                        .slice(0, 5);
                },

                currentLogoGeneratorSettings() {
                    return {
                        selected_model: this.selectedModel,
                        logo_count: Number(this.logoCount) || 2,
                        logo_domain: this.logoDomain || '',
                        logo_prompt: this.logoPrompt || '',
                        logo_style: this.logoStyle || 'default',
                        logo_theme: this.logoTheme || '',
                        logo_color_palette: this.logoColorPalette || 'none',
                        logo_custom_colors: this.normalizePaletteColors(this.logoCustomColors),
                        background_color: this.backgroundColor || 'white',
                        background_custom_color: this.normalizeHexColor(this.backgroundCustomColor, '#4F46E5'),
                        logo_mode: this.logoMode || 'icon_only',
                        pro_mode: Boolean(this.proMode),
                        pro_size: parseInt(this.proSize || '512', 10),
                        detail_level: this.detailLevel || 'medium',
                        shape_container: this.shapeContainer || '',
                        work_mode: this.workMode || 'logo',
                        output_format: this.outputFormat || 'vector',
                        image_format: this.imageFormat || 'png',
                        gen_mode: this.genMode || 'logo',
                        image_size: this.imageSize || '1:1',
                    };
                },

                applyLogoGeneratorSettings(settings, options = {}) {
                    if (!settings || typeof settings !== 'object' || Object.keys(settings).length === 0) {
                        return;
                    }

                    this.selectedModel = ['flux', 'recraft', 'dalle'].includes(settings.selected_model) ? settings.selected_model : this.selectedModel;
                    this.logoCount = Math.max(1, Math.min(4, parseInt(settings.logo_count || this.logoCount, 10) || this.logoCount));
                    this.logoDomain = String(settings.logo_domain || '');
                    this.logoPrompt = String(settings.logo_prompt || '');
                    this.logoStyle = String(settings.logo_style || 'default');
                    this.logoTheme = ['real_estate', 'nature'].includes(settings.logo_theme) ? settings.logo_theme : '';
                    this.logoColorPalette = String(settings.logo_color_palette || 'none');

                    const colors = this.normalizePaletteColors(settings.logo_custom_colors || []);
                    if (colors.length >= 2) {
                        this.logoCustomColors = colors;
                    }

                    const background = String(settings.background_color || 'white');
                    this.backgroundColor = background;
                    this.backgroundCustomColor = this.normalizeHexColor(settings.background_custom_color || (background.startsWith('#') ? background : '#4F46E5'), '#4F46E5');
                    this.logoMode = ['icon_only', 'icon_text', 'text_only'].includes(settings.logo_mode) ? settings.logo_mode : 'icon_only';
                    this.proMode = Boolean(settings.pro_mode);

                    const proSize = parseInt(settings.pro_size, 10);
                    this.proSize = String([512, 1024, 1536].includes(proSize) ? proSize : 512);
                    this.detailLevel = ['min', 'medium', 'max'].includes(settings.detail_level) ? settings.detail_level : 'medium';
                    this.shapeContainer = ['circle', 'square', 'hexagon', 'triangle', 'pentagon'].includes(settings.shape_container) ? settings.shape_container : '';
                    this.workMode = ['logo', 'image'].includes(settings.work_mode) ? settings.work_mode : 'logo';
                    this.outputFormat = ['raster', 'vector'].includes(settings.output_format) ? settings.output_format : 'vector';
                    this.imageFormat = ['png', 'bmp'].includes(settings.image_format) ? settings.image_format : 'png';
                    this.genMode = ['logo', 'image'].includes(settings.gen_mode) ? settings.gen_mode : 'logo';
                    this.imageSize = ['1:1', '16:9', '9:16'].includes(settings.image_size) ? settings.image_size : '1:1';

                    if (this.workMode === 'logo') {
                        this.outputFormat = 'vector';
                        this.genMode = 'logo';
                        if (this.selectedModel === 'dalle') {
                            this.selectedModel = 'recraft';
                        }
                        if (this.logoMode === 'icon_text') {
                            this.logoMode = 'icon_only';
                        }
                    }

                    if (this.selectedModel === 'dalle' && this.imageFormat !== 'png') {
                        this.imageFormat = 'png';
                    }

                    this.enforceLunaVectorDefaults();
                    if (options.fetch !== false) {
                        this.fetchLogoPrice();
                    }
                },

                async saveLogoGeneratorSettings() {
                    if (!this.canSaveSettings || this.settingsSaving) {
                        return;
                    }

                    this.settingsSaving = true;
                    this.settingsStatus = null;
                    this.settingsError = null;

                    try {
                        const response = await fetch('/domain-search/logo-generator-settings', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({ settings: this.currentLogoGeneratorSettings() }),
                        });
                        const data = await response.json().catch(() => ({}));

                        if (!response.ok) {
                            this.settingsError = data.error || data.message || 'Failed to save settings.';
                            return;
                        }

                        this.savedLogoSettings = data.settings || this.currentLogoGeneratorSettings();
                        this.settingsStatus = 'Settings saved.';
                    } catch (e) {
                        this.settingsError = 'Network error saving settings.';
                    } finally {
                        this.settingsSaving = false;
                    }
                },

                init() {
                    this.applyLogoGeneratorSettings(this.savedLogoSettings, { fetch: false });
                    if (String(this.backgroundColor || '').startsWith('#')) {
                        this.backgroundCustomColor = this.normalizeHexColor(this.backgroundColor, '#4F46E5');
                    }
                    this.enforceLunaVectorDefaults();
                    this.ensureSupportedImageSize();

                    // Delay initial price fetch to ensure everything is loaded
                    this.$nextTick(() => {
                        this.fetchLogoPrice();
                    });
                    this.fetchSavedPalettes();
                },

                selectModel(model) {
                    this.selectedModel = model;
                    if (['skyline_swoosh', 'evergreen_silhouette'].includes(this.logoStyle)) {
                        this.logoStyle = this.outputFormat === 'vector' ? 'minimal_geometric' : 'professional';
                    }
                    // Set default pro mode based on model
                    if (model === 'dalle') {
                        this.proMode = false;
                    } else if (model === 'recraft') {
                        this.proMode = true;
                    }
                    this.enforceLunaVectorDefaults();
                    this.ensureSupportedImageSize();
                    this.fetchLogoPrice();
                },

                switchToImageMode() {
                    this.workMode = 'image';
                    this.outputFormat = 'raster';
                    this.ensureSupportedImageSize();

                    if (this.selectedModel === 'dalle' && this.imageFormat !== 'png') {
                        this.imageFormat = 'png';
                    }

                    if (this.activeTab === 'editor') {
                        this.activeTab = 'generator';
                    }

                    const vectorStyles = ['minimal_geometric', 'abstract', 'monoline', 'negative_space', 'tech_gradient', 'skyline_swoosh', 'evergreen_silhouette', 'modern_sans', 'bold_geometric', 'elegant_serif', 'script_signature', 'tech_mono', 'minimal_light'];
                    if (vectorStyles.includes(this.logoStyle)) {
                        this.logoStyle = 'professional';
                    }

                    this.fetchLogoPrice();
                },

                switchToLogoMode() {
                    this.workMode = 'logo';
                    this.outputFormat = 'vector';
                    this.genMode = 'logo';

                    if (this.logoMode === 'icon_text') {
                        this.logoMode = 'icon_only';
                    }

                    if (this.selectedModel === 'dalle') {
                        this.selectedModel = 'recraft';
                    }

                    const rasterStyles = ['professional', 'fantasy', 'future', 'retro', 'minimalist', 'greetingcard', 'photorealistic'];
                    const textStyles = ['modern_sans', 'bold_geometric', 'elegant_serif', 'script_signature', 'tech_mono', 'minimal_light'];
                    if (this.logoMode === 'text_only') {
                        if (this.logoStyle !== 'default' && !textStyles.includes(this.logoStyle)) {
                            this.logoStyle = 'modern_sans';
                        }
                    } else if (this.logoStyle !== 'default' && (rasterStyles.includes(this.logoStyle) || textStyles.includes(this.logoStyle))) {
                        this.logoStyle = 'minimal_geometric';
                    }

                    this.enforceLunaVectorDefaults();
                    this.ensureSupportedImageSize();
                    this.fetchLogoPrice();
                },

                isLunaVectorMode() {
                    return this.selectedModel === 'flux' && this.outputFormat === 'vector';
                },

                isSampledVectorMode() {
                    return this.outputFormat === 'vector' && (this.selectedModel === 'flux' || this.selectedModel === 'recraft');
                },

                getVectorSampleUrl(style) {
                    const lunaSamples = {
                        minimal_geometric: '/images/luna_vector_samples/lion_minimal_luna.png',
                        abstract: '/images/luna_vector_samples/lion_abstract_luna.png',
                        monoline: '/images/luna_vector_samples/lion_monoline_luna.png',
                        negative_space: '/images/luna_vector_samples/lion_negative_space_luna.png',
                        tech_gradient: '/images/luna_vector_samples/tech_gradient_lion_luna.png',
                    };

                    const raySamples = {
                        minimal_geometric: '/images/ray_vector_samples/ray_minimal_vector.png',
                        abstract: '/images/ray_vector_samples/ray_abstract_vector.png',
                        monoline: '/images/ray_vector_samples/ray_monoline_vector.png',
                        negative_space: '/images/ray_vector_samples/ray_negative_space_vector.png',
                        tech_gradient: '/images/ray_vector_samples/ray_tech_gradient_vector.png',
                        evergreen_silhouette: '/images/ray_vector_samples/ray_evergreen_silhouette_vector.svg',
                    };

                    const sampleMap = this.selectedModel === 'recraft' ? raySamples : lunaSamples;
                    return sampleMap[style] || '';
                },

                isTextStyle(style) {
                    return ['modern_sans', 'bold_geometric', 'elegant_serif', 'script_signature', 'tech_mono', 'minimal_light'].includes(style);
                },

                enforceLunaVectorDefaults() {
                    if (this.isLunaVectorMode()) {
                        this.proMode = true;
                        this.proSize = '512';
                    }
                },

                getEffectiveProSettings() {
                    if (this.isLunaVectorMode()) {
                        return { pro: true, proSize: 512 };
                    }

                    if (this.selectedModel === 'recraft' && this.outputFormat === 'vector') {
                        return { pro: false, proSize: 512 };
                    }

                    return {
                        pro: this.proMode,
                        proSize: this.proMode ? parseInt(this.proSize) : 1024,
                    };
                },

                imageSizeOptions() {
                    if (this.selectedModel === 'recraft' && this.outputFormat === 'raster' && this.getEffectiveProSettings().pro) {
                        return [
                            { id: '1:1', label: 'Square' },
                        ];
                    }

                    return [
                        { id: '1:1', label: 'Square' },
                        { id: '16:9', label: 'Landscape' },
                        { id: '9:16', label: 'Portrait' },
                    ];
                },

                ensureSupportedImageSize() {
                    const supported = this.imageSizeOptions().map(option => option.id);
                    if (!supported.includes(this.imageSize)) {
                        this.imageSize = '1:1';
                    }
                },

                imageSizeResolutionLabel() {
                    if (this.selectedModel === 'dalle') {
                        if (this.imageSize === '16:9') return '1536x1024';
                        if (this.imageSize === '9:16') return '1024x1536';
                        return '1024x1024';
                    }

                    if (this.selectedModel === 'recraft') {
                        const isRayPro = Boolean(this.getEffectiveProSettings().pro);
                        if (this.imageSize === '16:9') return '1344x768';
                        if (this.imageSize === '9:16') return '768x1344';
                        return '1024x1024';
                    }

                    return this.imageSize;
                },

                getSelectedPaletteColors() {
                    if (this.logoColorPalette === 'none') return null;
                    if (this.logoColorPalette === 'custom') return this.logoCustomColors;
                    const p = this.colorPalettes.find(p => p.id === this.logoColorPalette);
                    return p ? p.colors : null;
                },

                isCustomBackgroundColor() {
                    return String(this.backgroundColor || '').startsWith('#');
                },

                selectCustomBackground() {
                    this.backgroundColor = this.normalizeHexColor(this.backgroundCustomColor, '#4F46E5');
                    this.fetchLogoPrice();
                },

                openAddTextModal() {
                    this.editingTextElement = null;
                    this.editorText = '';
                    this.editorFontFamily = 'Arial';
                    this.editorFontSize = 48;
                    this.editorFontBold = true;
                    this.editorFontItalic = false;
                    this.editorTextUseVector = false;
                    this.editorTextColor = '#000000';
                    this.showTextModal = true;
                },

                openTextEditorForSelected() {
                    const textEl = this.getSelectedTextElement();
                    if (!textEl) return;

                    this.editingTextElement = textEl;
                    this.editorText = textEl.textContent || '';
                    this.editorFontFamily = textEl.getAttribute('font-family') || this.editorFontFamily || 'Arial';
                    this.editorFontSize = parseFloat(textEl.getAttribute('font-size') || '48') || 48;

                    const weightRaw = String(textEl.getAttribute('font-weight') || '').toLowerCase();
                    const weightInt = parseInt(weightRaw, 10);
                    this.editorFontBold = weightRaw === 'bold' || (!Number.isNaN(weightInt) && weightInt >= 600);

                    const styleRaw = String(textEl.getAttribute('font-style') || '').toLowerCase();
                    this.editorFontItalic = styleRaw === 'italic' || styleRaw === 'oblique';

                    this.editorTextColor = textEl.getAttribute('fill') || this.editorTextColor || '#000000';
                    this.showTextModal = true;
                },

                saveTextModal() {
                    if (this.editingTextElement) {
                        this.editorText = String(this.editorText || '').trim();
                        if (!this.editorText) return;

                        const textEl = this.editingTextElement;
                        textEl.textContent = this.editorText;
                        textEl.setAttribute('font-family', this.editorFontFamily || 'Arial');
                        textEl.setAttribute('font-size', String(Math.max(8, Math.min(300, parseFloat(this.editorFontSize) || 48))));
                        textEl.setAttribute('font-weight', this.editorFontBold ? '700' : '400');
                        textEl.setAttribute('font-style', this.editorFontItalic ? 'italic' : 'normal');
                        textEl.setAttribute('fill', this.editorTextColor || '#000000');
                        textEl.setAttribute('data-layer-name', 'Text: ' + this.editorText.substring(0, 20));

                        this.syncTextEditorFromSelection();
                        this.updateLayers();
                        this.updateHoverMenuPosition();
                        this.showTextModal = false;
                        this.editingTextElement = null;
                        return;
                    }

                    this.addTextToSvg();
                    this.showTextModal = false;
                },

                applyCustomBackgroundColor(color) {
                    const hex = this.normalizeHexColor(color, '#4F46E5');
                    this.backgroundCustomColor = hex;
                    this.backgroundColor = hex;
                    this.fetchLogoPrice();
                },

                getEditorBackgroundFill() {
                    if (this.backgroundColor === 'white') return 'white';
                    if (String(this.backgroundColor || '').startsWith('#')) {
                        return this.normalizeHexColor(this.backgroundColor, 'white');
                    }
                    return null;
                },

                async fetchSavedPalettes() {
                    if (!this.canManagePalettes) return;
                    this.paletteLoading = true;
                    this.paletteError = null;
                    try {
                        const response = await fetch('/domain-search/logo-palettes', {
                            method: 'GET',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                'Accept': 'application/json',
                            },
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
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                'Accept': 'application/json',
                            },
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
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                'Accept': 'application/json',
                            },
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
                    const labels = { default: 'Default', chrome: 'Chrome', professional: 'Professional', fantasy: 'Fantasy', future: 'Future', retro: 'Retro', '8bit': '8-Bit', dotmatrix: 'Dot Matrix', greetingcard: 'Watercolor', photorealistic: 'Photorealistic', minimal_geometric: 'Minimal Geometric', abstract: 'Abstract', monoline: 'Monoline', negative_space: 'Negative Space', tech_gradient: 'Tech Gradient', skyline_swoosh: 'Skyline Swoosh', evergreen_silhouette: 'Evergreen Silhouette', modern_sans: 'Modern Sans', bold_geometric: 'Bold Geometric', elegant_serif: 'Elegant Serif', script_signature: 'Script Signature', tech_mono: 'Tech Mono', minimal_light: 'Minimal Light' };
                    if (labels[this.logoStyle]) return labels[this.logoStyle];
                    return 'Default';
                },

                getThemeLabel() {
                    const labels = { real_estate: 'Real Estate', nature: 'Nature' };
                    return labels[this.logoTheme] || 'None';
                },

                selectStyle(style) {
                    this.logoStyle = style;
                    this.showStyleModal = false;
                    this.fetchLogoPrice();
                },

                selectTheme(theme) {
                    this.logoTheme = theme;
                    this.showStyleModal = false;
                    this.fetchLogoPrice();
                },

                async fetchLogoPrice() {
                    try {
                        this.ensureSupportedImageSize();
                        const proSettings = this.getEffectiveProSettings();

                        // Ensure CSRF token is available
                        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
                        if (!csrfToken) {
                            console.warn('CSRF token not available yet, skipping price fetch');
                            return;
                        }

                        const response = await fetch('/domain-search/estimate-logo-price', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken
                            },
                            body: JSON.stringify({
                                count: this.logoCount,
                                pro: proSettings.pro,
                                pro_size: proSettings.proSize,
                                style: this.logoStyle,
                                bg_color: this.backgroundColor,
                                image_model: this.selectedModel,
                                output_format: this.outputFormat,
                                image_format: this.outputFormat === 'raster' && this.selectedModel === 'dalle' ? this.imageFormat : null,
                                gen_mode: this.workMode === 'image' && this.genMode === 'image' ? 'image' : 'logo',
                                image_size: this.workMode === 'image' && this.genMode === 'image' ? this.imageSize : null,
                                recraft_substyle: null
                            })
                        });

                        if (response.ok) {
                            const data = await response.json();
                            this.logoPrice = parseFloat(data.estimated_cost_usd) || 0;
                            if (data.credit_balance !== undefined) {
                                this.creditBalance = parseFloat(data.credit_balance);
                            }
                        } else {
                            console.error('Price estimate failed:', response.status, response.statusText);
                        }
                    } catch (err) {
                        console.error('Price estimate error:', err);
                    }
                },

                isDataImageUrl(url) {
                    return /^data:image\//i.test(String(url || ''));
                },

                isLocalLogoUrl(url) {
                    return String(url || '').startsWith('/storage/logos/');
                },

                isSvgUrl(url) {
                    return /\.svg(?:[?#]|$)/i.test(String(url || '')) || /^data:image\/svg\+xml/i.test(String(url || ''));
                },

                displayUrlForGeneratedImage(img) {
                    if (!img || typeof img !== 'object') return String(img || '');
                    const storedUrl = img.stored_url || '';
                    const rawUrl = img.url || '';
                    const svgUrl = img.svg_url || '';

                    if (storedUrl) return storedUrl;
                    if (this.isDataImageUrl(rawUrl)) return rawUrl;
                    if (rawUrl && (!this.isSvgUrl(rawUrl) || this.isLocalLogoUrl(rawUrl))) return rawUrl;
                    if (svgUrl && this.isLocalLogoUrl(svgUrl)) return svgUrl;

                    return rawUrl || svgUrl;
                },

                editUrlForGeneratedImage(img) {
                    if (!img || typeof img !== 'object') return String(img || '');
                    if (img.stored_url && this.isSvgUrl(img.stored_url)) return img.stored_url;
                    return img.svg_url || img.stored_url || img.url || '';
                },

                updateGeneratedImage(batchIndex, imageIndex, updates) {
                    const batch = this.logoBatches[batchIndex];
                    if (!batch || !batch.images || !batch.images[imageIndex]) return;

                    const images = batch.images.map((image, idx) => idx === imageIndex ? { ...image, ...updates } : image);
                    this.logoBatches[batchIndex] = { ...batch, images };
                },

                async upscaleGeneratedImage(batchIndex, imageIndex) {
                    const batch = this.logoBatches[batchIndex];
                    const image = batch?.images?.[imageIndex];
                    if (!image || image.failed || image.isVector || image.upscaling) return;

                    const sourceUrl = image.displayUrl || image.editUrl || image.url;
                    if (!sourceUrl) {
                        this.updateGeneratedImage(batchIndex, imageIndex, { upscaleError: 'No image URL is available to upscale.' });
                        return;
                    }

                    this.error = null;
                    this.updateGeneratedImage(batchIndex, imageIndex, { upscaling: true, upscaleError: null });
                    const abortController = new AbortController();
                    const timeoutHandle = window.setTimeout(() => abortController.abort(), 180000);

                    try {
                        const response = await fetch('/domain-search/upscale-logo', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({
                                image_url: sourceUrl,
                                upscale_factor: 2,
                                logo_request_id: image.logoRequestId || null,
                                image_index: Number.isInteger(image.imageIndex) ? image.imageIndex : imageIndex,
                            }),
                            signal: abortController.signal,
                        });

                        const data = await response.json().catch(() => ({ error: 'Server returned invalid response.' }));
                        if (!response.ok) {
                            const message = data.error || 'Upsize failed.';
                            this.error = message;
                            if (data.credit_balance !== undefined) {
                                this.creditBalance = parseFloat(data.credit_balance);
                            }
                            this.updateGeneratedImage(batchIndex, imageIndex, { upscaling: false, upscaleError: message });
                            return;
                        }

                        const resolution = data.width && data.height ? `${data.width}x${data.height}` : '2x upscale';
                        this.updateGeneratedImage(batchIndex, imageIndex, {
                            url: data.upscaled_url,
                            displayUrl: data.upscaled_url,
                            editUrl: data.upscaled_url,
                            upscaledUrl: data.upscaled_url,
                            originalUrl: sourceUrl,
                            upscaling: false,
                            upscaleError: null,
                            metadata: {
                                ...(image.metadata || batch.metadata || {}),
                                resolution,
                                upscaled: true,
                            },
                        });

                        if (data.credit_balance !== undefined) {
                            this.creditBalance = parseFloat(data.credit_balance);
                        }
                    } catch (err) {
                        const message = err.name === 'AbortError'
                            ? 'Upsize is taking longer than expected. Please try again in a moment.'
                            : (err.message || 'Upsize failed.');
                        this.error = message;
                        this.updateGeneratedImage(batchIndex, imageIndex, { upscaling: false, upscaleError: message });
                    } finally {
                        window.clearTimeout(timeoutHandle);
                        this.updateGeneratedImage(batchIndex, imageIndex, { upscaling: false });
                    }
                },

                async generateLogo() {
                    this.ensureSupportedImageSize();
                    const proSettings = this.getEffectiveProSettings();

                    // Validate based on mode
                    const needsText = this.logoMode === 'icon_text' || this.logoMode === 'text_only';
                    const needsPrompt = this.logoMode === 'icon_only' || this.logoMode === 'icon_text';

                    if (this.workMode === 'logo' && this.logoMode === 'icon_text') {
                        this.error = 'Vector generation supports either logo or text, not both.';
                        return;
                    }
                    
                    if (needsText && !this.logoDomain) {
                        this.error = 'Please enter logo text';
                        return;
                    }
                    if (needsPrompt && !this.logoPrompt) {
                        // Prompt is optional, but at least something is needed
                        if (!this.logoDomain) {
                            this.error = 'Please enter logo text or a custom prompt';
                            return;
                        }
                    }
                    
	                    this.error = null;

	                    const estimatedTotalCost = Number(this.logoPrice || 0);
	                    const availableCreditBalance = Number(this.creditBalance || 0);
	                    if (estimatedTotalCost > 0 && availableCreditBalance < estimatedTotalCost) {
	                        this.error = 'Insufficient balance. Please add credits before generating logos.';
	                        return;
	                    }

	                    this.generating = true;
                    
                    // Create a new batch at the beginning with loading placeholders
                    const batchId = Date.now();
                    const expectedCount = this.logoCount;
                    
                    // Store metadata for this generation
                    const isRayVector = this.selectedModel === 'recraft' && this.outputFormat === 'vector';
                    const isImageContentBatch = this.workMode === 'image' && this.genMode === 'image';
                    const generationMetadata = {
                        model: this.selectedModel === 'flux' ? 'Luna' : (this.selectedModel === 'recraft' ? 'Ray' : 'Cosmo'),
                        modelId: this.selectedModel,
                        resolution: isImageContentBatch ? this.imageSizeResolutionLabel() : (isRayVector ? 'SVG 1:1' : (proSettings.pro && this.selectedModel === 'flux' ? `${proSettings.proSize}x${proSettings.proSize}` : (this.selectedModel === 'dalle' ? '1024x1024' : '512x512'))),
                        price: (this.logoPrice / this.logoCount).toFixed(4),
                        style: this.logoTheme ? this.getThemeLabel() : this.logoStyle
                    };
                    
                    this.logoBatches.unshift({
                        id: batchId,
                        timestamp: new Date().toISOString(),
                        images: [],
                        metadata: generationMetadata,
                        loading: true,
                        expectedCount: expectedCount
                    });

                    try {
                        // Step 1: Queue one generation job for the full requested count.
                        const totalCount = this.logoCount;
                        const pendingJobs = [];
                        const isImageContent = this.workMode === 'image' && this.genMode === 'image';
                        const payload = {
                            domain: (this.logoMode === 'icon_text' || this.logoMode === 'text_only') ? this.logoDomain : null,
                            custom_prompt: this.logoPrompt || '',
                            style: this.logoStyle,
                            logo_theme: this.logoTheme || null,
                            count: totalCount,
                            total_count: totalCount,
                            batch_index: 0,
                            pro: proSettings.pro,
                            pro_size: proSettings.proSize,
                            icon_only: isImageContent ? false : this.logoMode === 'icon_only',
                            text_only: isImageContent ? false : this.logoMode === 'text_only',
                            bg_color: this.backgroundColor,
                            image_model: this.selectedModel,
                            output_format: this.outputFormat,
                            image_format: this.outputFormat === 'raster' && this.selectedModel === 'dalle' ? this.imageFormat : null,
                            color_palette: this.logoColorPalette !== 'none' ? this.getSelectedPaletteColors() : null,
                            logo_shape: this.shapeContainer || 'none',
                            logo_detail: this.detailLevel || 'medium',
                            gen_mode: isImageContent ? 'image' : 'logo',
                            image_size: isImageContent ? this.imageSize : null
                        };

                        const response = await fetch('/domain-search/generate-logo', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            },
                            body: JSON.stringify(payload)
                        });

                        if (!response.ok) {
                            const data = await response.json().catch(() => ({ error: 'Server error' }));
                            this.error = data.error || 'Failed to queue logo generation';
                            if (data.credit_balance !== undefined) {
                                this.creditBalance = parseFloat(data.credit_balance);
                            }
                            this.logoBatches = this.logoBatches.filter(batch => batch.id !== batchId);
                            this.generating = false;
                            return;
                        }

                        const data = await response.json().catch(() => {
                            console.error('Failed to parse generation response as JSON');
                            return null;
                        });

                        if (!data || !data.logo_request_id) {
                            this.error = 'Server returned invalid response';
                            this.logoBatches = this.logoBatches.filter(batch => batch.id !== batchId);
                            this.generating = false;
                            return;
                        }

                        pendingJobs.push(data.logo_request_id);

                        if (data.credit_balance !== undefined) {
                            this.creditBalance = parseFloat(data.credit_balance);
                        }

                        // Step 2: Poll for completion
                        const completedJobs = new Set();
                        const failedJobs = new Set();
                        const maxPollTime = 6 * 60 * 1000; // backend job timeout plus a little room
                        const pollStart = Date.now();
                        const pollInterval = 3000; // 3 seconds

                        while (completedJobs.size + failedJobs.size < pendingJobs.length) {
                            if (Date.now() - pollStart > maxPollTime) {
                                this.error = 'Logo generation is still processing. Refresh in a moment to check the latest status.';
                                break;
                            }

                            await new Promise(resolve => setTimeout(resolve, pollInterval));

                            for (const jobId of pendingJobs) {
                                if (completedJobs.has(jobId) || failedJobs.has(jobId)) continue;

                                try {
                                    const statusRes = await fetch('/domain-search/logo-status/' + jobId, {
                                        headers: {
                                            'Content-Type': 'application/json',
                                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                                        }
                                    });

                                    const statusData = await statusRes.json().catch(err => {
                                        console.error('Failed to parse status response as JSON for job', jobId, err);
                                        return { status: 'failed', error: 'Invalid server response' };
                                    });

                                    if (statusData.status === 'completed') {
                                        completedJobs.add(jobId);

                                        const batchIdx = this.logoBatches.findIndex(b => b.id === batchId);
                                        const existingBatch = batchIdx !== -1 ? this.logoBatches[batchIdx] : null;
                                        const newImages = (statusData.images || []).map((img, resultIndex) => {
                                            const displayUrl = this.displayUrlForGeneratedImage(img);
                                            const editUrl = this.editUrlForGeneratedImage(img);
                                            return {
                                                key: `${jobId}-${resultIndex}`,
                                                url: displayUrl,
                                                displayUrl,
                                                editUrl,
                                                logoRequestId: jobId,
                                                imageIndex: resultIndex,
                                                seed: statusData.seed || null,
                                                isVector: this.outputFormat === 'vector',
                                                metadata: existingBatch?.metadata || {}
                                            };
                                        });

                                        // Replace batch object to ensure Alpine detects the nested array change
                                        if (batchIdx !== -1) {
                                            this.logoBatches[batchIdx] = {
                                                ...this.logoBatches[batchIdx],
                                                images: [...this.logoBatches[batchIdx].images, ...newImages]
                                            };
                                        }

                                        if (statusData.credit_balance !== undefined) {
                                            this.creditBalance = parseFloat(statusData.credit_balance);
                                        }
                                    } else if (statusData.status === 'failed' || statusData.status === 'error') {
                                        failedJobs.add(jobId);
                                        this.error = statusData.error || 'Logo generation failed.';

                                        // Replace batch object to ensure Alpine detects the nested array change
                                        const batchIdx = this.logoBatches.findIndex(b => b.id === batchId);
                                        if (batchIdx !== -1) {
                                            this.logoBatches[batchIdx] = {
                                                ...this.logoBatches[batchIdx],
                                                images: [...this.logoBatches[batchIdx].images, {
                                                    key: `${jobId}-failed`,
                                                    url: null,
                                                    failed: true,
                                                    error: statusData.error || 'Generation failed',
                                                    seed: null,
                                                    isVector: this.outputFormat === 'vector',
                                                    metadata: this.logoBatches[batchIdx].metadata || {}
                                                }]
                                            };
                                        }

                                        if (statusData.credit_balance !== undefined) {
                                            this.creditBalance = parseFloat(statusData.credit_balance);
                                        }
                                    }
                                } catch (e) {
                                    console.error('Polling error:', e);
                                }
                            }
                        }

                        // Check if target batch has images
                        const finalBatchIdx = this.logoBatches.findIndex(b => b.id === batchId);
                        if (finalBatchIdx !== -1) {
                            const finalBatch = this.logoBatches[finalBatchIdx];
                            this.logoBatches[finalBatchIdx] = { ...finalBatch, loading: false };
                            if (finalBatch.images.length > 0) {
                                this.queueSimilarIdeasLookup();
                            }
                        }

                        this.generating = false;
                    } catch (err) {
	                        this.error = err.message || 'An error occurred while generating logos';
	                        this.generating = false;

	                        // Remove empty placeholder batches; keep batches that already contain job results.
	                        const finalBatchIdx = this.logoBatches.findIndex(b => b.id === batchId);
	                        if (finalBatchIdx !== -1) {
	                            if ((this.logoBatches[finalBatchIdx].images || []).length === 0) {
	                                this.logoBatches.splice(finalBatchIdx, 1);
	                            } else {
	                                this.logoBatches[finalBatchIdx] = { ...this.logoBatches[finalBatchIdx], loading: false };
	                            }
	                        }
	                    }
                },

                async saveLogo(url) {
                    try {
                        const response = await fetch('/domain-search/save-logo', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            },
                            body: JSON.stringify({ url })
                        });

                        const data = await response.json();
                        if (data.success) {
                            alert('Logo saved successfully!');
                        }
                    } catch (err) {
                        console.error('Save error:', err);
                    }
                },

                async convertToSvg(url) {
                    try {
                        const response = await fetch('/domain-search/convert-to-svg', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            },
                            body: JSON.stringify({ url })
                        });

                        const data = await response.json();
                        if (data.svg_url) {
                            window.open(data.svg_url, '_blank');
                        }
                    } catch (err) {
                        console.error('SVG conversion error:', err);
                    }
                },

                openInEditor(url) {
                    window.open(`/pdf-editor?logo_url=${encodeURIComponent(url)}`, '_blank');
                },

                async removeBackground(url) {
                    try {
                        // Check if it's an SVG (vector)
                        const isVector = url.toLowerCase().endsWith('.svg');
                        
                        if (isVector) {
                            // For SVG: Fetch, parse, remove backgrounds, and save
                            const svgResponse = await fetch(url);
                            const svgText = await svgResponse.text();
                            const parser = new DOMParser();
                            const svgDoc = parser.parseFromString(svgText, 'image/svg+xml');
                            const svgElement = svgDoc.documentElement;
                            
                            // Get viewBox for processing
                            const viewBoxValues = (svgElement.getAttribute('viewBox') || '0 0 512 512')
                                .split(/\s+/)
                                .map((v) => parseFloat(v));
                            
                            // Remove white backgrounds using the same logic as removeSvgBackground
                            this.removeWhiteBackgrounds(svgElement, viewBoxValues);
                            
                            // Remove editor background markers
                            const editorBg = svgElement.querySelectorAll('[data-editor-bg="true"]');
                            editorBg.forEach((el) => el.remove());
                            
                            // Remove full-page backgrounds more aggressively
                            const [vbX, vbY, vbWidth, vbHeight] = viewBoxValues;
                            
                            // Remove white background rects
                            const rects = svgElement.querySelectorAll('rect');
                            rects.forEach((rect) => {
                                const x = parseFloat(rect.getAttribute('x') || 0);
                                const y = parseFloat(rect.getAttribute('y') || 0);
                                const width = parseFloat(rect.getAttribute('width') || 0);
                                const height = parseFloat(rect.getAttribute('height') || 0);
                                const widthRatio = width / vbWidth;
                                const heightRatio = height / vbHeight;
                                const nearOrigin = Math.abs(x - vbX) <= 8 && Math.abs(y - vbY) <= 8;
                                const fullPage = widthRatio >= 0.95 && heightRatio >= 0.95;
                                if (nearOrigin && fullPage) {
                                    rect.remove();
                                }
                            });
                            
                            // Remove white background paths (check first element only)
                            const paths = svgElement.querySelectorAll('path');
                            if (paths.length > 0) {
                                const firstPath = paths[0];
                                const fill = firstPath.getAttribute('fill');
                                const d = firstPath.getAttribute('d');
                                
                                // Check if white
                                const fillLower = (fill || '').toLowerCase().trim();
                                const isWhite = fillLower === 'white' || 
                                               fillLower === '#fff' || 
                                               fillLower === '#ffffff' ||
                                               fillLower.match(/rgb\(25[0-5],?\s*25[0-5],?\s*25[0-5]\)/);
                                
                                if (isWhite && d) {
                                    // Check if it's a full-page rectangle path
                                    const rectPattern = /M\s*[\d.\-]+[\s,]+[\d.\-]+\s*L\s*[\d.\-]+[\s,]+[\d.\-]+\s*L\s*[\d.\-]+[\s,]+[\d.\-]+\s*L\s*[\d.\-]+[\s,]+[\d.\-]+\s*[LZ]/i;
                                    if (rectPattern.test(d)) {
                                        const coords = d.match(/[\d.\-]+/g);
                                        if (coords && coords.length >= 8) {
                                            const pathWidth = Math.max(parseFloat(coords[2]), parseFloat(coords[4]));
                                            const pathHeight = Math.max(parseFloat(coords[3]), parseFloat(coords[5]));
                                            const coversFullPage = (pathWidth >= vbWidth * 0.95 && pathHeight >= vbHeight * 0.95);
                                            if (coversFullPage) {
                                                firstPath.remove();
                                            }
                                        }
                                    }
                                }
                            }
                            
                            // Convert back to string and upload
                            const serializer = new XMLSerializer();
                            const processedSvg = serializer.serializeToString(svgElement);
                            
                            // Upload the processed SVG
                            const uploadResponse = await fetch('/domain-search/save-processed-svg', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                                },
                                body: JSON.stringify({ svg: processedSvg })
                            });
                            
                            const uploadData = await uploadResponse.json();
                            if (uploadData.url) {
                                // Add the new SVG to the most recent batch
                                if (this.logoBatches.length > 0) {
                                    this.logoBatches[0].images.push({ url: uploadData.url, isVector: true, metadata: {} });
                                } else {
                                    this.logoBatches.unshift({
                                        id: Date.now(),
                                        timestamp: new Date().toISOString(),
                                        images: [{ url: uploadData.url, isVector: true, metadata: {} }],
                                        loading: false,
                                        metadata: {}
                                    });
                                }
                            }
                        } else {
                            // For raster images: Use existing API
                            const response = await fetch('/domain-search/remove-background', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                                },
                                body: JSON.stringify({ url })
                            });

                            const data = await response.json();
                            if (data.url) {
                                // Add the new image to the most recent batch
                                if (this.logoBatches.length > 0) {
                                    this.logoBatches[0].images.push({ url: data.url, isVector: false, metadata: {} });
                                } else {
                                    this.logoBatches.unshift({
                                        id: Date.now(),
                                        timestamp: new Date().toISOString(),
                                        images: [{ url: data.url, isVector: false, metadata: {} }],
                                        loading: false,
                                        metadata: {}
                                    });
                                }
                            }
                        }
                    } catch (err) {
                        console.error('Background removal error:', err);
                        alert('Error removing background. Please try again.');
                    }
                },

                useSeed(seed) {
                    this.seed = seed;
                    alert('Seed ' + seed + ' will be used for next generation');
                },

                async useAsPrompt(url) {
                    try {
                        const response = await fetch('/domain-search/describe-logo', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            },
                            body: JSON.stringify({ url })
                        });

                        const data = await response.json();
                        if (data.description) {
                            this.logoPrompt = data.description;
                        }
                    } catch (err) {
                        console.error('Describe error:', err);
                    }
                },

                zoomImage(url) {
                    this.zoomImageUrl = url;
                },

                async openEditorTab() {
                    return false;
                },

                setCanvasSize() {
                    if (!this.editorSvgElement) return;
                    
                    let width, height;
                    
                    switch (this.canvasSize) {
                        case 'letter':
                            // 8.5" x 11" at 96 DPI
                            width = 816;
                            height = 1056;
                            break;
                        case 'business-card':
                            // 3.5" x 2" at 96 DPI
                            width = 336;
                            height = 192;
                            break;
                        case 'default':
                        default:
                            // Keep a roomy default workspace instead of snapping back to tiny source bounds.
                            const rootGroup = this.editorSvgElement.querySelector('g[id^="original-svg-"]');
                            const baseWidth = parseFloat(rootGroup?.getAttribute('data-original-width')) || 512;
                            const baseHeight = parseFloat(rootGroup?.getAttribute('data-original-height')) || 512;
                            width = Math.max(baseWidth, 900);
                            height = Math.max(baseHeight, 900);
                            break;
                    }
                    
                    // Get old canvas dimensions before changing
                    const oldViewBox = this.editorSvgElement.getAttribute('viewBox').split(' ');
                    const oldWidth = parseFloat(oldViewBox[2]);
                    const oldHeight = parseFloat(oldViewBox[3]);
                    
                    // Update viewBox to new canvas size
                    this.editorSvgElement.setAttribute('viewBox', `0 0 ${width} ${height}`);
                    
                    // Scale all groups to maintain relative size on new canvas
                    const groups = this.editorSvgElement.querySelectorAll('g[id^="original-svg-"], g[id^="imported-"]');
                    groups.forEach(group => {
                        // Get current transform
                        const transform = group.getAttribute('transform') || '';
                        let currentScale = 1;
                        let translateX = 0;
                        let translateY = 0;
                        
                        const scaleMatch = transform.match(/scale\(([^)]+)\)/);
                        const translateMatch = transform.match(/translate\(([^,]+),\s*([^)]+)\)/);
                        
                        if (scaleMatch) currentScale = parseFloat(scaleMatch[1]);
                        if (translateMatch) {
                            translateX = parseFloat(translateMatch[1]);
                            translateY = parseFloat(translateMatch[2]);
                        }
                        
                        // Calculate new scale based on canvas size change
                        // Make content fill 90% of the smaller dimension for larger display
                        const oldCanvasSize = Math.min(oldWidth, oldHeight);
                        const newCanvasSize = Math.min(width, height);
                        const scaleFactor = (newCanvasSize / oldCanvasSize) * 0.9;
                        
                        // Center the scaled content
                        const newTranslateX = (width - (oldWidth * scaleFactor)) / 2;
                        const newTranslateY = (height - (oldHeight * scaleFactor)) / 2;
                        
                        group.setAttribute('transform', `translate(${newTranslateX}, ${newTranslateY}) scale(${scaleFactor})`);
                    });
                    
                    // Maintain responsive sizing
                    this.editorSvgElement.setAttribute('width', '100%');
                    this.editorSvgElement.setAttribute('height', 'auto');
                },

                updateElementInteractivity() {
                    if (!this.editorSvgElement) return;
                    this.clearInlineOutlines();
                    
                    // In edit mode we only want child-element editing, not whole-group dragging.
                    const groups = this.editorSvgElement.querySelectorAll('g[id^="original-svg-"], g[id^="imported-"]');
                    groups.forEach(g => {
                        g.style.cursor = this.editMode ? 'default' : 'move';
                    });
                    
                    if (this.editMode) {
                        this.hideHoverMenu();
                        this.clearSelection();
                        this.makeElementsClickable();
                    } else {
                        this.removeElementClickHandlers();
                    }
                },

                makeElementsClickable() {
                    if (!this.editorSvgElement) return;
                    
                    const elements = this.editorSvgElement.querySelectorAll('path, circle, rect, ellipse, polygon, polyline, line, text');
                    
                    elements.forEach(el => {
                        if (el.tagName?.toLowerCase() === 'text' && el.closest('g[data-vectorized-text="1"]')) {
                            return;
                        }

                        // Remove existing listeners to avoid duplicates
                        const newEl = el.cloneNode(true);
                        el.parentNode.replaceChild(newEl, el);
                        
                        newEl.style.cursor = 'grab';
                        newEl.setAttribute('data-clickable', 'true');
                        
                        // Add click to select
                        newEl.addEventListener('click', (e) => {
                            e.stopPropagation();
                            this.selectElement(newEl);
                        });

                        // Double-click text to open full text editor instantly.
                        if (newEl.tagName?.toLowerCase() === 'text') {
                            newEl.addEventListener('dblclick', (e) => {
                                e.stopPropagation();
                                this.selectElement(newEl);
                                this.openTextEditorForSelected();
                            });
                        }
                        
                        // Make element draggable
                        this.makeElementDraggable(newEl);
                    });
                },

                removeElementClickHandlers() {
                    if (!this.editorSvgElement) return;
                    
                    const elements = this.editorSvgElement.querySelectorAll('[data-clickable="true"]');
                    elements.forEach(el => {
                        el.style.outline = '';
                        el.style.cursor = '';
                        el.removeAttribute('data-clickable');
                        // Clone to remove event listeners
                        const newEl = el.cloneNode(true);
                        newEl.style.outline = '';
                        el.parentNode.replaceChild(newEl, el);
                    });
                    
                    // Clear selection when switching to move mode
                    this.clearSelection();
                },

                parseGroupTransform(transform) {
                    let translateX = 0;
                    let translateY = 0;
                    let scale = 1;
                    if (!transform) return { translateX, translateY, scale };

                    const translateMatch = transform.match(/translate\(([^,]+),\s*([^)]+)\)/);
                    const scaleMatch = transform.match(/scale\(([^)]+)\)/);
                    if (translateMatch) {
                        translateX = parseFloat(translateMatch[1]) || 0;
                        translateY = parseFloat(translateMatch[2]) || 0;
                    }
                    if (scaleMatch) {
                        scale = parseFloat(scaleMatch[1]) || 1;
                    }

                    return { translateX, translateY, scale };
                },

                getSelectionBounds(element) {
                    try {
                        const bbox = element.getBBox();
                        
                        // For groups, parse their transform
                        if (element.tagName?.toLowerCase() === 'g') {
                            const t = this.parseGroupTransform(element.getAttribute('transform'));
                            return {
                                x: t.translateX + (bbox.x * t.scale),
                                y: t.translateY + (bbox.y * t.scale),
                                width: bbox.width * t.scale,
                                height: bbox.height * t.scale,
                            };
                        }
                        
                        // For individual elements in edit mode, account for their transform
                        if (this.editGroupMode && element.getAttribute('transform')) {
                            // Parse the element's transform
                            const transform = element.getAttribute('transform');
                            const translateMatch = transform.match(/translate\(([^,]+),\s*([^)]+)\)/);
                            
                            if (translateMatch) {
                                const translateX = parseFloat(translateMatch[1]);
                                const translateY = parseFloat(translateMatch[2]);
                                
                                // Also check parent group transform
                                const parentGroup = this.editingGroup;
                                if (parentGroup) {
                                    const parentTransform = this.parseGroupTransform(parentGroup.getAttribute('transform'));
                                    return {
                                        x: parentTransform.translateX + bbox.x + translateX,
                                        y: parentTransform.translateY + bbox.y + translateY,
                                        width: bbox.width * parentTransform.scale,
                                        height: bbox.height * parentTransform.scale,
                                    };
                                }
                                
                                return {
                                    x: bbox.x + translateX,
                                    y: bbox.y + translateY,
                                    width: bbox.width,
                                    height: bbox.height,
                                };
                            }
                        }

                        return { x: bbox.x, y: bbox.y, width: bbox.width, height: bbox.height };
                    } catch (error) {
                        return null;
                    }
                },

                getSelectedElementColor(element) {
                    if (!element) return '#d1d5db';
                    if (element.tagName?.toLowerCase() === 'g') {
                        const coloredChild = element.querySelector('[fill]:not([fill=\"none\"]), [stroke]:not([stroke=\"none\"])');
                        if (!coloredChild) return '#d1d5db';
                        return coloredChild.getAttribute('fill') || coloredChild.getAttribute('stroke') || '#d1d5db';
                    }
                    return element.getAttribute('fill') || element.getAttribute('stroke') || element.style.fill || element.style.stroke || '#d1d5db';
                },

                getSelectedTextElement() {
                    if (!this.selectedElements.length) return null;
                    const el = this.selectedElements[this.selectedElements.length - 1];
                    return el?.tagName?.toLowerCase() === 'text' ? el : null;
                },

                hasSelectedTextElement() {
                    return this.getSelectedTextElement() !== null;
                },

                syncTextEditorFromSelection() {
                    const el = this.getSelectedTextElement();
                    if (!el) {
                        this.selectedTextContent = '';
                        return;
                    }

                    const fontFamily = el.getAttribute('font-family') || el.style.fontFamily || this.editorFontFamily;
                    const fontSizeRaw = el.getAttribute('font-size') || el.style.fontSize || this.editorFontSize;
                    const fontWeightRaw = String(el.getAttribute('font-weight') || el.style.fontWeight || '').toLowerCase();
                    const fontStyleRaw = String(el.getAttribute('font-style') || el.style.fontStyle || '').toLowerCase();

                    const parsedSize = parseFloat(fontSizeRaw);
                    const parsedWeight = parseInt(fontWeightRaw, 10);

                    this.selectedTextContent = el.textContent || '';
                    this.selectedTextFontFamily = fontFamily;
                    this.selectedTextFontSize = Number.isFinite(parsedSize) ? parsedSize : 48;
                    this.selectedTextBold = fontWeightRaw === 'bold' || (!Number.isNaN(parsedWeight) && parsedWeight >= 600);
                    this.selectedTextItalic = fontStyleRaw === 'italic' || fontStyleRaw === 'oblique';
                },

                applySelectedTextChanges() {
                    const el = this.getSelectedTextElement();
                    if (!el) return;

                    const safeSize = Math.min(300, Math.max(8, parseFloat(this.selectedTextFontSize) || 48));
                    const nextText = String(this.selectedTextContent ?? '');

                    el.textContent = nextText;
                    el.setAttribute('font-family', this.selectedTextFontFamily || 'Arial');
                    el.setAttribute('font-size', String(safeSize));
                    el.setAttribute('font-weight', this.selectedTextBold ? '700' : '400');
                    el.setAttribute('font-style', this.selectedTextItalic ? 'italic' : 'normal');
                    el.setAttribute('data-layer-name', 'Text: ' + (nextText || 'Untitled').substring(0, 20));

                    this.updateLayers();
                    this.updateHoverMenuPosition();
                },

                toggleSelectedTextBold() {
                    this.selectedTextBold = !this.selectedTextBold;
                    this.applySelectedTextChanges();
                },

                toggleSelectedTextItalic() {
                    this.selectedTextItalic = !this.selectedTextItalic;
                    this.applySelectedTextChanges();
                },

                hideHoverMenu() {
                    this.hoverMenu.visible = false;
                },

                getElementScreenBounds(element) {
                    if (!element || typeof element.getBoundingClientRect !== 'function') return null;
                    const rect = element.getBoundingClientRect();
                    if (!Number.isFinite(rect.left) || !Number.isFinite(rect.top) || rect.width <= 0 || rect.height <= 0) {
                        return null;
                    }
                    return {
                        left: rect.left,
                        top: rect.top,
                        right: rect.right,
                        bottom: rect.bottom,
                        width: rect.width,
                        height: rect.height,
                    };
                },

                updateHoverMenuPosition() {
                    if (!this.selectedElements.length || !this.editorSvgElement) {
                        this.hideHoverMenu();
                        return;
                    }

                    const surfaceRect = null;
                    if (!surfaceRect) {
                        this.hideHoverMenu();
                        return;
                    }

                    // In edit mode, anchor to the exact selected element.
                    // Outside edit mode, anchor to the union of selected elements.
                    let anchorLeft;
                    let anchorTop;
                    let anchorRight;

                    if (this.editMode && this.selectedElements.length > 0) {
                        const anchorEl = this.selectedElements[this.selectedElements.length - 1];
                        const bounds = this.getElementScreenBounds(anchorEl);
                        if (!bounds) {
                            this.hideHoverMenu();
                            return;
                        }
                        anchorLeft = bounds.left;
                        anchorTop = bounds.top;
                        anchorRight = bounds.right;
                    } else {
                        let minLeft = Number.POSITIVE_INFINITY;
                        let minTop = Number.POSITIVE_INFINITY;
                        let maxRight = Number.NEGATIVE_INFINITY;
                        let found = false;

                        this.selectedElements.forEach((el) => {
                            const bounds = this.getElementScreenBounds(el);
                            if (!bounds) return;
                            minLeft = Math.min(minLeft, bounds.left);
                            minTop = Math.min(minTop, bounds.top);
                            maxRight = Math.max(maxRight, bounds.right);
                            found = true;
                        });

                        if (!found) {
                            this.hideHoverMenu();
                            return;
                        }

                        anchorLeft = minLeft;
                        anchorTop = minTop;
                        anchorRight = maxRight;
                    }

                    this.hoverMenu.x = ((anchorLeft + anchorRight) / 2) - surfaceRect.left;
                    this.hoverMenu.y = anchorTop - surfaceRect.top - 14;
                    this.hoverMenu.visible = true;
                },

                clearSelection() {
                    this.selectedElements.forEach((el) => {
                        if (!el) return;
                        el.style.outline = '';
                        if (typeof el.__hideResizeBox === 'function') {
                            el.__hideResizeBox();
                        }
                        // Hide bounding boxes
                        this.hideElementBoundingBox(el, 'selected');
                        this.hideElementBoundingBox(el, 'hover');
                    });
                    this.selectedElements = [];
                    this.editGroupMode = false;
                    this.editingGroup = null;
                    this.clearInlineOutlines();
                    this.hideHoverMenu();
                    this.syncTextEditorFromSelection();
                },

                clearInlineOutlines() {
                    if (!this.editorSvgElement) return;
                    const outlined = this.editorSvgElement.querySelectorAll('*');
                    outlined.forEach((el) => {
                        if (el?.style?.outline) {
                            el.style.outline = '';
                        }
                    });
                },

                selectMoveModeElements(elements) {
                    this.clearSelection();
                    this.selectedElements = elements.filter(Boolean);

                    if (this.selectedElements.length === 1 && typeof this.selectedElements[0].__showResizeBox === 'function') {
                        this.selectedElements[0].__showResizeBox();
                    }

                    if (this.selectedElements.length > 0) {
                        this.selectedElementColor = this.getSelectedElementColor(this.selectedElements[0]);
                        this.updateHoverMenuPosition();
                    }
                    this.syncTextEditorFromSelection();
                },

                selectMoveModeGroup(group) {
                    this.selectMoveModeElements([group]);
                },

                selectElement(element, addToSelection = false) {
                    // In editGroupMode, allow selection of child elements even if they're groups
                    // In normal mode, clicking a group should select the whole group
                    if (!this.editMode && !this.editGroupMode && element?.tagName?.toLowerCase() === 'g') {
                        this.selectMoveModeGroup(element);
                        return;
                    }

                    if (!addToSelection) {
                        // Clear previous selection
                        this.selectedElements.forEach(el => {
                            if (el) el.style.outline = '';
                        });
                        this.selectedElements = [];
                    }
                    
                    // Add element to selection
                    if (element && !this.selectedElements.includes(element)) {
                        this.selectedElements.push(element);
                        element.style.outline = '2px solid #8b5cf6';
                    }
                    
                    // Get current color from first selected element
                    if (this.selectedElements.length > 0) {
                        const firstEl = this.selectedElements[0];
                        const fill = firstEl.getAttribute('fill') || firstEl.style.fill;
                        const stroke = firstEl.getAttribute('stroke') || firstEl.style.stroke;
                        this.selectedElementColor = fill && fill !== 'none' ? fill : (stroke || '#000000');
                        this.updateHoverMenuPosition();
                    } else {
                        this.hideHoverMenu();
                    }
                    this.syncTextEditorFromSelection();
                },

                updateSelectedElementColor(newColor) {
                    if (this.selectedElements.length === 0) return;
                    
                    // Update the swatch color
                    this.selectedElementColor = newColor;
                    
                    this.selectedElements.forEach(element => {
                        const fill = element.getAttribute('fill');
                        const stroke = element.getAttribute('stroke');
                        
                        if (fill && fill !== 'none') {
                            element.setAttribute('fill', newColor);
                        }
                        if (stroke && stroke !== 'none') {
                            element.setAttribute('stroke', newColor);
                        }
                        
                        // Also check style
                        if (element.style.fill) {
                            element.style.fill = newColor;
                        }
                        if (element.style.stroke) {
                            element.style.stroke = newColor;
                        }
                    });
                },

                makeElementDraggable(element) {
                    // Clean up existing drag handlers if any
                    if (typeof element.__destroyElementDraggable === 'function') {
                        element.__destroyElementDraggable();
                    }
                    
                    let isDragging = false;
                    let hasDragged = false;
                    let startX, startY;
                    let currentTransform = { translateX: 0, translateY: 0 };
                    let rafId = null;
                    
                    // Parse existing transform if present
                    const existingTransform = element.getAttribute('transform');
                    if (existingTransform) {
                        const translateMatch = existingTransform.match(/translate\(([^,]+),\s*([^)]+)\)/);
                        if (translateMatch) {
                            currentTransform.translateX = parseFloat(translateMatch[1]) || 0;
                            currentTransform.translateY = parseFloat(translateMatch[2]) || 0;
                        }
                    }
                    
                    // Get parent group's scale to adjust drag speed
                    const getParentScale = () => {
                        let parent = element.parentElement;
                        while (parent && parent !== this.editorSvgElement) {
                            if (parent.tagName === 'g') {
                                const transform = parent.getAttribute('transform');
                                if (transform) {
                                    const parsed = this.parseGroupTransform(transform);
                                    if (parsed.scale !== 1) {
                                        return parsed.scale;
                                    }
                                }
                            }
                            parent = parent.parentElement;
                        }
                        return 1;
                    };
                    
                    const getCoords = (e) => {
                        const svg = this.editorSvgElement;
                        const pt = svg.createSVGPoint();
                        pt.x = e.clientX || e.touches?.[0]?.clientX || 0;
                        pt.y = e.clientY || e.touches?.[0]?.clientY || 0;
                        return pt.matrixTransform(svg.getScreenCTM().inverse());
                    };
                    
                    const onStart = (e) => {
                        // In editGroupMode, allow any child element to be dragged
                        // In normal editMode, only drag if element is selected
                        if (!this.editGroupMode && !this.selectedElements.includes(element)) return;
                        
                        isDragging = true;
                        hasDragged = false;
                        element.style.cursor = 'grabbing';
                        
                        const coords = getCoords(e);
                        startX = coords.x;
                        startY = coords.y;
                        
                        e.stopPropagation();
                        e.preventDefault();
                    };
                    
                    const onMove = (e) => {
                        if (!isDragging) return;
                        
                        hasDragged = true;
                        
                        // Cancel any pending animation frame
                        if (rafId) cancelAnimationFrame(rafId);
                        
                        rafId = requestAnimationFrame(() => {
                            const coords = getCoords(e);
                            let dx = coords.x - startX;
                            let dy = coords.y - startY;
                            
                            // Adjust for parent group's scale transform
                            const parentScale = getParentScale();
                            if (parentScale !== 1) {
                                dx = dx / parentScale;
                                dy = dy / parentScale;
                            }
                            
                            currentTransform.translateX += dx;
                            currentTransform.translateY += dy;
                            
                            // Build transform string, preserving other transforms
                            let transformStr = `translate(${currentTransform.translateX}, ${currentTransform.translateY})`;
                            
                            // Preserve scale, rotate, etc. if they exist
                            const existingTransform = element.getAttribute('transform') || '';
                            const scaleMatch = existingTransform.match(/scale\([^)]+\)/);
                            const rotateMatch = existingTransform.match(/rotate\([^)]+\)/);
                            
                            if (scaleMatch) transformStr += ` ${scaleMatch[0]}`;
                            if (rotateMatch) transformStr += ` ${rotateMatch[0]}`;
                            
                            element.setAttribute('transform', transformStr);
                            if (this.selectedElements.includes(element)) {
                                this.updateHoverMenuPosition();
                            }
                            
                            startX = coords.x;
                            startY = coords.y;
                        });
                        
                        e.preventDefault();
                    };
                    
                    const onEnd = () => {
                        if (isDragging) {
                            isDragging = false;
                            element.style.cursor = 'grab';
                            
                            if (rafId) {
                                cancelAnimationFrame(rafId);
                                rafId = null;
                            }
                            
                            // Update bounding boxes after dragging in edit mode
                            if (this.editGroupMode && element.__bboxId) {
                                // Check if this element has bounding boxes and redraw them
                                const hasHoverBox = this.editorSvgElement.querySelector(`[data-bbox-id="bbox-hover-${element.__bboxId}"]`);
                                const hasSelectedBox = this.editorSvgElement.querySelector(`[data-bbox-id="bbox-selected-${element.__bboxId}"]`);
                                
                                if (hasHoverBox) {
                                    this.hideElementBoundingBox(element, 'hover');
                                    this.showElementBoundingBox(element, 'hover');
                                }
                                if (hasSelectedBox) {
                                    this.hideElementBoundingBox(element, 'selected');
                                    this.showElementBoundingBox(element, 'selected');
                                }
                                
                                // Update hover menu position if this element is selected
                                if (this.selectedElements.includes(element)) {
                                    this.updateHoverMenuPosition();
                                }
                            }
                        }
                    };
                    
                    // Prevent click event if we actually dragged
                    const preventClickIfDragged = (e) => {
                        if (hasDragged) {
                            e.stopPropagation();
                            e.preventDefault();
                            hasDragged = false;
                        }
                    };
                    
                    element.addEventListener('mousedown', onStart);
                    element.addEventListener('touchstart', onStart);
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('touchmove', onMove);
                    document.addEventListener('mouseup', onEnd);
                    document.addEventListener('touchend', onEnd);
                    element.addEventListener('click', preventClickIfDragged, true);
                    
                    // Store cleanup function
                    element.__destroyElementDraggable = () => {
                        element.removeEventListener('mousedown', onStart);
                        element.removeEventListener('touchstart', onStart);
                        document.removeEventListener('mousemove', onMove);
                        document.removeEventListener('touchmove', onMove);
                        document.removeEventListener('mouseup', onEnd);
                        document.removeEventListener('touchend', onEnd);
                        element.removeEventListener('click', preventClickIfDragged, true);
                    };
                },

                startMarqueeSelection(e) {
                    if (!this.editorSvgElement) return;
                    
                    // Get SVG coordinates
                    const pt = this.editorSvgElement.createSVGPoint();
                    pt.x = e.clientX;
                    pt.y = e.clientY;
                    const svgPt = pt.matrixTransform(this.editorSvgElement.getScreenCTM().inverse());
                    
                    // Initialize selection
                    this.isSelecting = true;
                    this.selectionStartX = svgPt.x;
                    this.selectionStartY = svgPt.y;
                    this.selectionEndX = svgPt.x;
                    this.selectionEndY = svgPt.y;
                    
                    // Show selection box
                    if (this.selectionRectElement) {
                        this.editorSvgElement.appendChild(this.selectionRectElement);
                        this.selectionRectElement.style.display = 'block';
                        this.selectionRectElement.style.opacity = '1';
                        this.selectionRectElement.setAttribute('x', this.selectionStartX);
                        this.selectionRectElement.setAttribute('y', this.selectionStartY);
                        this.selectionRectElement.setAttribute('width', 0);
                        this.selectionRectElement.setAttribute('height', 0);
                    }
                    
                    // Add mousemove and mouseup listeners
                    const onMove = (e) => this.updateMarqueeSelection(e);
                    const onEnd = (e) => {
                        this.endMarqueeSelection(e);
                        document.removeEventListener('mousemove', onMove);
                        document.removeEventListener('mouseup', onEnd);
                    };
                    
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('mouseup', onEnd);
                    
                    e.preventDefault();
                },

                updateMarqueeSelection(e) {
                    if (!this.isSelecting || !this.editorSvgElement || !this.selectionRectElement) return;
                    
                    // Get SVG coordinates
                    const pt = this.editorSvgElement.createSVGPoint();
                    pt.x = e.clientX;
                    pt.y = e.clientY;
                    const svgPt = pt.matrixTransform(this.editorSvgElement.getScreenCTM().inverse());
                    
                    this.selectionEndX = svgPt.x;
                    this.selectionEndY = svgPt.y;
                    
                    // Calculate rectangle dimensions (handle negative width/height)
                    const x = Math.min(this.selectionStartX, this.selectionEndX);
                    const y = Math.min(this.selectionStartY, this.selectionEndY);
                    const width = Math.abs(this.selectionEndX - this.selectionStartX);
                    const height = Math.abs(this.selectionEndY - this.selectionStartY);
                    
                    // Update selection box
                    this.selectionRectElement.setAttribute('x', x);
                    this.selectionRectElement.setAttribute('y', y);
                    this.selectionRectElement.setAttribute('width', width);
                    this.selectionRectElement.setAttribute('height', height);
                },

                endMarqueeSelection(e) {
                    if (!this.isSelecting || !this.editorSvgElement) return;
                    
                    this.isSelecting = false;
                    
                    // Hide selection box
                    if (this.selectionRectElement) {
                        this.selectionRectElement.style.display = 'none';
                    }
                    
                    // Calculate selection rectangle
                    const x = Math.min(this.selectionStartX, this.selectionEndX);
                    const y = Math.min(this.selectionStartY, this.selectionEndY);
                    const width = Math.abs(this.selectionEndX - this.selectionStartX);
                    const height = Math.abs(this.selectionEndY - this.selectionStartY);
                    
                    // If selection box is too small (just a click), clear selection
                    if (width < 5 && height < 5) {
                        this.clearSelection();
                        return;
                    }

                    if (this.editMode) {
                        const elements = this.editorSvgElement.querySelectorAll('[data-clickable=\"true\"]');

                        this.selectedElements.forEach(el => {
                            if (el) el.style.outline = '';
                        });
                        this.selectedElements = [];

                        elements.forEach(el => {
                            try {
                                const bbox = el.getBBox();
                                const intersects = !(
                                    bbox.x + bbox.width < x ||
                                    bbox.x > x + width ||
                                    bbox.y + bbox.height < y ||
                                    bbox.y > y + height
                                );

                        if (intersects) {
                            this.selectedElements.push(el);
                            if (this.editMode) {
                                el.style.outline = '2px solid #8b5cf6';
                            }
                        }
                            } catch (err) {
                                console.warn('Could not get bbox for element', el, err);
                            }
                        });

                        if (this.selectedElements.length > 0) {
                            const firstEl = this.selectedElements[0];
                            const fill = firstEl.getAttribute('fill') || firstEl.style.fill;
                            const stroke = firstEl.getAttribute('stroke') || firstEl.style.stroke;
                            this.selectedElementColor = fill && fill !== 'none' ? fill : (stroke || '#000000');
                        }
                        return;
                    }

                    const groups = this.editorSvgElement.querySelectorAll('g[id^="original-svg-"], g[id^="imported-"]');
                    const selectedGroups = [];
                    groups.forEach((group) => {
                        const bbox = this.getSelectionBounds(group);
                        if (!bbox) return;
                        const intersects = !(
                            bbox.x + bbox.width < x ||
                            bbox.x > x + width ||
                            bbox.y + bbox.height < y ||
                            bbox.y > y + height
                        );
                        if (intersects) selectedGroups.push(group);
                    });

                    this.selectMoveModeElements(selectedGroups);
                },

                openReplaceMenu() {
                    if (!this.selectedElements.length) return;
                    this.replaceTargetElement = this.selectedElements[0];
                    this.showImportModal = true;
                },

                duplicateSelectedLogos() {
                    if (!this.editorSvgElement || this.selectedElements.length === 0) return;
                    const clones = [];
                    this.selectedElements.forEach((el, index) => {
                        if (el.tagName?.toLowerCase() !== 'g') return;
                        const clone = el.cloneNode(true);
                        clone.setAttribute('id', `imported-${Date.now()}-${index}`);
                        const t = this.parseGroupTransform(clone.getAttribute('transform'));
                        clone.setAttribute('transform', `translate(${t.translateX + 30}, ${t.translateY + 30}) scale(${t.scale})`);
                        this.editorSvgElement.appendChild(clone);
                        this.makeGroupDraggable(clone);
                        clones.push(clone);
                    });

                    this.selectMoveModeElements(clones);
                    this.updateLayers();
                },

                moveElementBackward() {
                    if (!this.editorSvgElement || this.selectedElements.length === 0) return;
                    
                    this.selectedElements.forEach((el) => {
                        const parent = el.parentElement;
                        if (!parent) return;
                        
                        // Get the previous sibling (element before this one)
                        const previousSibling = el.previousElementSibling;
                        
                        // Can't move back if already at the beginning or previous is selection rect
                        if (!previousSibling || previousSibling.id === 'selection-rect') return;
                        
                        // Insert current element before the previous sibling
                        parent.insertBefore(el, previousSibling);
                    });
                    
                    this.updateLayers();
                    this.updateHoverMenuPosition();
                },

                moveElementForward() {
                    if (!this.editorSvgElement || this.selectedElements.length === 0) return;
                    
                    this.selectedElements.forEach((el) => {
                        const parent = el.parentElement;
                        if (!parent) return;
                        
                        // Get the next sibling (element after this one)
                        const nextSibling = el.nextElementSibling;
                        
                        // Can't move forward if already at the end or next is selection rect
                        if (!nextSibling || nextSibling.id === 'selection-rect') return;
                        
                        // Insert current element after the next sibling
                        if (nextSibling.nextElementSibling) {
                            parent.insertBefore(el, nextSibling.nextElementSibling);
                        } else {
                            parent.appendChild(el);
                        }
                    });
                    
                    this.updateLayers();
                    this.updateHoverMenuPosition();
                },

                makeHolesTransparent() {
                    if (!this.editorSvgElement || this.selectedElements.length === 0) return;
                    
                    let processedCount = 0;
                    
                    this.selectedElements.forEach((el) => {
                        // Get all paths in this element
                        const paths = Array.from(el.querySelectorAll('path, rect, circle, ellipse, polygon, polyline'));
                        
                        // Separate white-filled (holes) from colored (letters)
                        const whitePaths = [];
                        const coloredPaths = [];
                        
                        paths.forEach(path => {
                            const fill = path.getAttribute('fill') || '';
                            const styleFill = path.style.fill || '';
                            
                            const isWhite = 
                                fill === 'rgb(255,255,255)' || 
                                fill === '#ffffff' || 
                                fill === '#fff' || 
                                fill === 'white' ||
                                styleFill === 'rgb(255,255,255)' || 
                                styleFill === '#ffffff' || 
                                styleFill === '#fff' || 
                                styleFill === 'white';
                            
                            if (isWhite) {
                                whitePaths.push(path);
                            } else if (fill !== 'none' && fill !== '' && fill !== 'transparent') {
                                coloredPaths.push(path);
                            }
                        });
                        
                        if (whitePaths.length === 0) {
                            console.log('No white-filled holes found in this element');
                            return;
                        }
                        
                        // Create a unique mask ID
                        const maskId = `mask-holes-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
                        
                        // Create a mask element
                        const mask = document.createElementNS('http://www.w3.org/2000/svg', 'mask');
                        mask.setAttribute('id', maskId);
                        
                        // Add a white rectangle as the base (everything visible)
                        const whiteBase = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                        whiteBase.setAttribute('x', '-100%');
                        whiteBase.setAttribute('y', '-100%');
                        whiteBase.setAttribute('width', '300%');
                        whiteBase.setAttribute('height', '300%');
                        whiteBase.setAttribute('fill', 'white');
                        mask.appendChild(whiteBase);
                        
                        // Add white paths as black in the mask (to cut out those areas)
                        whitePaths.forEach(whitePath => {
                            const maskPath = whitePath.cloneNode(true);
                            maskPath.setAttribute('fill', 'black'); // Black = transparent in mask
                            maskPath.removeAttribute('style');
                            mask.appendChild(maskPath);
                        });
                        
                        // Add mask to SVG defs
                        let defs = this.editorSvgElement.querySelector('defs');
                        if (!defs) {
                            defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
                            this.editorSvgElement.insertBefore(defs, this.editorSvgElement.firstChild);
                        }
                        defs.appendChild(mask);
                        
                        // Apply mask to colored paths
                        coloredPaths.forEach(coloredPath => {
                            coloredPath.setAttribute('mask', `url(#${maskId})`);
                        });
                        
                        // Remove the white paths (they're now in the mask)
                        whitePaths.forEach(whitePath => {
                            whitePath.remove();
                        });
                        
                        processedCount++;
                    });
                    
                    console.log(`Created masks for ${processedCount} elements to make letter holes transparent`);
                    
                    if (processedCount > 0) {
                        this.updateLayers();
                    }
                },

                groupSelectedLogos() {
                    if (!this.editorSvgElement || this.selectedElements.length < 2) return;
                    const selectedGroups = this.selectedElements.filter((el) => el.tagName?.toLowerCase() === 'g');
                    if (selectedGroups.length < 2) return;

                    const wrapper = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                    wrapper.setAttribute('id', `imported-${Date.now()}`);
                    wrapper.setAttribute('data-layer-name', 'Grouped Logos');
                    this.editorSvgElement.appendChild(wrapper);
                    selectedGroups.forEach((group) => wrapper.appendChild(group));

                    this.makeGroupDraggable(wrapper);
                    this.selectMoveModeElements([wrapper]);
                    this.updateLayers();
                },

                enterEditGroupMode() {
                    if (!this.selectedElements.length || !this.editorSvgElement) return;
                    
                    const group = this.selectedElements[0];
                    if (group.tagName !== 'g' || group.children.length <= 1) return;
                    
                    // Store reference to the group being edited
                    this.editingGroup = group;
                    this.editGroupMode = true;
                    
                    // Clear current selection outlines
                    this.selectedElements.forEach(el => {
                        if (el && typeof el.__hideResizeBox === 'function') {
                            el.__hideResizeBox();
                        }
                    });
                    
                    // Get all child elements
                    const childElements = Array.from(group.children).filter(child => 
                        child.tagName === 'path' || child.tagName === 'circle' || 
                        child.tagName === 'rect' || child.tagName === 'ellipse' || 
                        child.tagName === 'polygon' || child.tagName === 'g' ||
                        child.tagName === 'text'
                    );
                    
                    // Clear selection initially - user will click to select
                    this.selectedElements = [];
                    
                    // Add hover and click behavior to each child element
                    childElements.forEach(el => {
                        if (el) {
                            el.style.cursor = 'pointer';
                            
                            // Make each child element individually draggable
                            this.makeElementDraggable(el);
                            
                            // Add hover effect to show bounding box
                            const onMouseEnter = () => {
                                if (!this.selectedElements.includes(el)) {
                                    this.showElementBoundingBox(el, 'hover');
                                }
                            };
                            
                            const onMouseLeave = () => {
                                if (!this.selectedElements.includes(el)) {
                                    this.hideElementBoundingBox(el, 'hover');
                                }
                            };
                            
                            // Add click to select
                            const onClick = (e) => {
                                e.stopPropagation();
                                this.selectEditModeElement(el);
                            };
                            
                            el.addEventListener('mouseenter', onMouseEnter);
                            el.addEventListener('mouseleave', onMouseLeave);
                            el.addEventListener('click', onClick);
                            
                            // Store cleanup function
                            el.__cleanupEditMode = () => {
                                el.removeEventListener('mouseenter', onMouseEnter);
                                el.removeEventListener('mouseleave', onMouseLeave);
                                el.removeEventListener('click', onClick);
                                this.hideElementBoundingBox(el, 'hover');
                                this.hideElementBoundingBox(el, 'selected');
                            };
                        }
                    });
                    
                    this.hideHoverMenu();
                },

                selectEditModeElement(element) {
                    if (!this.editGroupMode) return;
                    
                    // Clear previous selection
                    this.selectedElements.forEach(el => {
                        this.hideElementBoundingBox(el, 'selected');
                        this.hideElementBoundingBox(el, 'hover');
                    });
                    
                    // Select new element
                    this.selectedElements = [element];
                    this.showElementBoundingBox(element, 'selected');
                    
                    // Update color from selected element
                    const fill = element.getAttribute('fill') || element.style.fill;
                    const stroke = element.getAttribute('stroke') || element.style.stroke;
                    this.selectedElementColor = fill && fill !== 'none' ? fill : (stroke || '#000000');
                    this.syncTextEditorFromSelection();
                    
                    // Show and position hover menu
                    this.hoverMenu.visible = true;
                    this.updateHoverMenuPosition();
                },

                showElementBoundingBox(element, type = 'hover') {
                    if (!element) return;
                    
                    // Use element reference to track boxes
                    if (!element.__bboxId) {
                        element.__bboxId = `el-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
                    }
                    const boxId = `bbox-${type}-${element.__bboxId}`;
                    
                    // Remove existing box of this type for this element
                    const existing = this.editorSvgElement.querySelector(`[data-bbox-id="${boxId}"]`);
                    if (existing) existing.remove();
                    
                    try {
                        const bbox = element.getBBox();
                        const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                        
                        rect.setAttribute('data-bbox-id', boxId);
                        rect.setAttribute('x', bbox.x - 2);
                        rect.setAttribute('y', bbox.y - 2);
                        rect.setAttribute('width', bbox.width + 4);
                        rect.setAttribute('height', bbox.height + 4);
                        rect.setAttribute('fill', 'none');
                        rect.setAttribute('stroke', type === 'selected' ? '#8b5cf6' : '#3b82f6');
                        rect.setAttribute('stroke-width', type === 'selected' ? '2' : '1');
                        rect.setAttribute('stroke-dasharray', type === 'selected' ? '0' : '5,5');
                        rect.style.pointerEvents = 'none';
                        
                        // Apply both the parent group's transform and the element's own transform
                        const parentTransform = this.editingGroup?.getAttribute('transform') || '';
                        const elementTransform = element.getAttribute('transform') || '';
                        
                        // Combine transforms: parent first, then element
                        let combinedTransform = '';
                        if (parentTransform && elementTransform) {
                            combinedTransform = `${parentTransform} ${elementTransform}`;
                        } else if (parentTransform) {
                            combinedTransform = parentTransform;
                        } else if (elementTransform) {
                            combinedTransform = elementTransform;
                        }
                        
                        if (combinedTransform) {
                            rect.setAttribute('transform', combinedTransform);
                        }
                        
                        this.editorSvgElement.appendChild(rect);
                    } catch (err) {
                        console.warn('Could not create bounding box:', err);
                    }
                },

                hideElementBoundingBox(element, type = 'hover') {
                    if (!element || !element.__bboxId) return;
                    
                    const boxId = `bbox-${type}-${element.__bboxId}`;
                    const existing = this.editorSvgElement?.querySelector(`[data-bbox-id="${boxId}"]`);
                    if (existing) existing.remove();
                },

                exitEditGroupMode() {
                    if (!this.editGroupMode) return;
                    
                    this.editGroupMode = false;
                    
                    // Get all child elements from the editing group
                    const childElements = this.editingGroup ? Array.from(this.editingGroup.children) : [];
                    
                    // Clean up all child elements
                    childElements.forEach(el => {
                        if (el) {
                            el.style.outline = '';
                            el.style.cursor = '';
                            // Clean up drag handlers
                            if (typeof el.__destroyElementDraggable === 'function') {
                                el.__destroyElementDraggable();
                            }
                            // Clean up edit mode listeners
                            if (typeof el.__cleanupEditMode === 'function') {
                                el.__cleanupEditMode();
                            }
                        }
                    });
                    
                    // Also clean up selected elements
                    this.selectedElements.forEach(el => {
                        if (el) {
                            el.style.outline = '';
                            el.style.cursor = '';
                        }
                    });
                    
                    // Re-select the parent group
                    if (this.editingGroup) {
                        this.selectMoveModeElements([this.editingGroup]);
                        this.editingGroup = null;
                    } else if (this.selectedElements.length > 0) {
                        const parentGroup = this.selectedElements[0].parentElement;
                        if (parentGroup && parentGroup.tagName === 'g') {
                            this.selectMoveModeElements([parentGroup]);
                        } else {
                            this.clearSelection();
                        }
                    }
                },

                buildStarPoints(cx, cy, outerRadius, innerRadius, points = 5) {
                    const pts = [];
                    const step = Math.PI / points;
                    let angle = -Math.PI / 2;

                    for (let i = 0; i < points * 2; i++) {
                        const r = i % 2 === 0 ? outerRadius : innerRadius;
                        const x = cx + Math.cos(angle) * r;
                        const y = cy + Math.sin(angle) * r;
                        pts.push(`${x},${y}`);
                        angle += step;
                    }

                    return pts.join(' ');
                },

                addShapeToSvg() {
                    if (!this.editorSvgElement) return;

                    const viewBox = this.editorSvgElement.getAttribute('viewBox')?.split(' ') || [0, 0, 512, 512];
                    const width = parseFloat(viewBox[2]) || 512;
                    const height = parseFloat(viewBox[3]) || 512;
                    const centerX = width / 2;
                    const centerY = height / 2;

                    const size = Math.max(20, Math.min(400, parseFloat(this.editorShapeSize) || 120));
                    const strokeWidth = Math.max(0, Math.min(20, parseFloat(this.editorShapeStrokeWidth) || 0));
                    const fill = this.normalizeHexColor(this.editorShapeFill, '#38BDF8');
                    const stroke = this.normalizeHexColor(this.editorShapeStroke, '#0F172A');

                    const wrapper = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                    wrapper.setAttribute('id', `imported-${Date.now()}`);
                    wrapper.setAttribute('data-layer-name', `${this.editorShapeType[0].toUpperCase()}${this.editorShapeType.slice(1)} Shape`);

                    let shapeEl = null;
                    const half = size / 2;

                    if (this.editorShapeType === 'line') {
                        shapeEl = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                        shapeEl.setAttribute('x1', centerX - half);
                        shapeEl.setAttribute('y1', centerY);
                        shapeEl.setAttribute('x2', centerX + half);
                        shapeEl.setAttribute('y2', centerY);
                        shapeEl.setAttribute('fill', 'none');
                    } else if (this.editorShapeType === 'rectangle') {
                        shapeEl = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                        shapeEl.setAttribute('x', centerX - half);
                        shapeEl.setAttribute('y', centerY - half);
                        shapeEl.setAttribute('width', size);
                        shapeEl.setAttribute('height', size);
                        shapeEl.setAttribute('fill', fill);
                    } else if (this.editorShapeType === 'circle') {
                        shapeEl = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                        shapeEl.setAttribute('cx', centerX);
                        shapeEl.setAttribute('cy', centerY);
                        shapeEl.setAttribute('r', half);
                        shapeEl.setAttribute('fill', fill);
                    } else if (this.editorShapeType === 'triangle') {
                        shapeEl = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
                        const points = [
                            `${centerX},${centerY - half}`,
                            `${centerX - half},${centerY + half}`,
                            `${centerX + half},${centerY + half}`,
                        ].join(' ');
                        shapeEl.setAttribute('points', points);
                        shapeEl.setAttribute('fill', fill);
                    } else if (this.editorShapeType === 'star') {
                        shapeEl = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
                        shapeEl.setAttribute('points', this.buildStarPoints(centerX, centerY, half, half * 0.45, 5));
                        shapeEl.setAttribute('fill', fill);
                    }

                    if (!shapeEl) return;

                    shapeEl.setAttribute('stroke', stroke);
                    shapeEl.setAttribute('stroke-width', String(strokeWidth));
                    shapeEl.style.cursor = 'move';
                    wrapper.appendChild(shapeEl);
                    this.editorSvgElement.appendChild(wrapper);

                    this.makeGroupDraggable(wrapper);
                    this.selectMoveModeElements([wrapper]);
                    this.updateLayers();
                },

                addTextToSvg() {
                    if (!this.editorText || !this.editorSvgElement) return;
                    
                    const viewBox = this.editorSvgElement.getAttribute('viewBox')?.split(' ') || [0, 0, 512, 512];
                    const width = parseFloat(viewBox[2]);
                    const height = parseFloat(viewBox[3]);
                    
                    const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                    text.setAttribute('x', width / 2);
                    text.setAttribute('y', height - 50);
                    text.setAttribute('text-anchor', 'middle');
                    text.setAttribute('font-family', this.editorFontFamily);
                    text.setAttribute('font-size', this.editorFontSize);
                    text.setAttribute('fill', this.editorTextColor);
                    text.setAttribute('font-weight', this.editorFontBold ? '700' : '400');
                    text.setAttribute('font-style', this.editorFontItalic ? 'italic' : 'normal');
                    text.style.cursor = 'move';
                    text.textContent = this.editorText;
                    if (this.editorTextUseVector) {
                        const wrapper = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                        wrapper.setAttribute('id', `imported-${Date.now()}`);
                        wrapper.setAttribute('data-layer-name', 'Vector Text: ' + this.editorText.substring(0, 20));
                        wrapper.setAttribute('data-vectorized-text', '1');
                        wrapper.appendChild(text);
                        this.editorSvgElement.appendChild(wrapper);
                        this.makeGroupDraggable(wrapper);
                        this.selectMoveModeElements([wrapper]);
                    } else {
                        text.setAttribute('data-layer-name', 'Text: ' + this.editorText.substring(0, 20));
                        this.makeDraggable(text);
                        this.editorSvgElement.appendChild(text);
                    }
                    this.updateLayers();
                    
                    this.editorText = '';
                    this.editorTextUseVector = false;
                },

                updateLayers() {
                    if (!this.editorSvgElement) return;
                    
                    // Only show top-level groups (imported vectors) and standalone text elements
                    const directChildren = Array.from(this.editorSvgElement.children);
                    const layerElements = directChildren.filter(el => {
                        // Show groups (imported vectors) or text elements added directly
                        return el.tagName === 'g' || el.tagName === 'text';
                    });
                    
                    this.svgLayers = layerElements.map((el, index) => {
                        let layerName = el.getAttribute('data-layer-name');
                        
                        // If no custom name, generate one based on element type
                        if (!layerName) {
                            if (el.tagName === 'g') {
                                layerName = el.id || 'Group ' + (index + 1);
                            } else {
                                layerName = 'Text ' + (index + 1);
                            }
                        }
                        
                        return {
                            id: Date.now() + index,
                            element: el,
                            name: layerName,
                            visible: el.style.display !== 'none',
                            isGroup: el.tagName === 'g'
                        };
                    });
                },

                selectLayerElement(element) {
                    this.selectElement(element);
                },

                selectLayer(layerId) {
                    const layer = this.svgLayers.find(l => l.id === layerId);
                    if (layer && layer.element) {
                        this.selectElement(layer.element);
                        // If element has click handler for showing resize box
                        if (layer.element._clickHandler) {
                            layer.element._clickHandler({ stopPropagation: () => {} });
                        }
                    }
                },

                toggleLayerVisibility(layerId) {
                    const layer = this.svgLayers.find(l => l.id === layerId);
                    if (layer) {
                        layer.visible = !layer.visible;
                        layer.element.style.display = layer.visible ? '' : 'none';
                    }
                },

                deleteLayer(layerId) {
                    const layer = this.svgLayers.find(l => l.id === layerId);
                    if (layer) {
                        // Save to undo stack before deleting
                        const element = layer.element;
                        const parent = element.parentNode;
                        const nextSibling = element.nextSibling;
                        const clonedElement = element.cloneNode(true);
                        
                        this.undoStack.push({
                            batch: [{
                                element: clonedElement,
                                parent: parent,
                                nextSibling: nextSibling
                            }],
                            timestamp: Date.now()
                        });
                        
                        // Limit undo stack to 10 items
                        if (this.undoStack.length > 10) {
                            this.undoStack.shift();
                        }
                        
                        // Remove from selection if selected
                        const index = this.selectedElements.indexOf(layer.element);
                        if (index > -1) {
                            this.selectedElements.splice(index, 1);
                        }
                        layer.element.remove();
                        this.updateLayers();
                        this.syncTextEditorFromSelection();
                    }
                },

                makeDraggable(element) {
                    let isDragging = false;
                    let startX, startY, initialX, initialY;
                    
                    const getCoords = (e) => {
                        const svg = this.editorSvgElement;
                        const pt = svg.createSVGPoint();
                        pt.x = e.clientX || e.touches?.[0]?.clientX || 0;
                        pt.y = e.clientY || e.touches?.[0]?.clientY || 0;
                        return pt.matrixTransform(svg.getScreenCTM().inverse());
                    };
                    
                    const onStart = (e) => {
                        isDragging = true;
                        element.style.cursor = 'grabbing';
                        
                        const coords = getCoords(e);
                        startX = coords.x;
                        startY = coords.y;
                        initialX = parseFloat(element.getAttribute('x') || 0);
                        initialY = parseFloat(element.getAttribute('y') || 0);
                        
                        e.preventDefault();
                    };
                    
                    const onMove = (e) => {
                        if (!isDragging) return;
                        
                        const coords = getCoords(e);
                        const dx = coords.x - startX;
                        const dy = coords.y - startY;
                        
                        element.setAttribute('x', initialX + dx);
                        element.setAttribute('y', initialY + dy);
                        
                        e.preventDefault();
                    };
                    
                    const onEnd = () => {
                        isDragging = false;
                        element.style.cursor = 'move';
                    };
                    
                    element.addEventListener('mousedown', onStart);
                    element.addEventListener('touchstart', onStart);
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('touchmove', onMove);
                    document.addEventListener('mouseup', onEnd);
                    document.addEventListener('touchend', onEnd);
                },

                downloadEditedSvg() {
                    if (!this.editorSvgElement) return;
                    
                    // Clone the SVG to avoid modifying the original
                    const svgClone = this.editorSvgElement.cloneNode(true);
                    
                    // Remove all resize boxes and selection box (UI elements)
                    const resizeBoxes = svgClone.querySelectorAll('.resize-box');
                    resizeBoxes.forEach(box => box.remove());
                    const selectionBoxes = svgClone.querySelectorAll('.selection-box');
                    selectionBoxes.forEach(box => box.remove());
                    
                    const serializer = new XMLSerializer();
                    const svgString = serializer.serializeToString(svgClone);
                    
                    const blob = new Blob([svgString], { type: 'image/svg+xml' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'edited-logo-' + Date.now() + '.svg';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                },

                deleteSelectedElement() {
                    if (this.selectedElements.length === 0) return;
                    
                    // Save all selected elements to undo stack before deleting
                    const deletedBatch = [];
                    
                    this.selectedElements.forEach(element => {
                        const parent = element.parentNode;
                        const nextSibling = element.nextSibling;
                        
                        // Clone the element to preserve it
                        const clonedElement = element.cloneNode(true);
                        
                        deletedBatch.push({
                            element: clonedElement,
                            parent: parent,
                            nextSibling: nextSibling
                        });
                        
                        // Remove the element itself
                        element.remove();
                    });
                    
                    // Store batch in undo stack
                    this.undoStack.push({
                        batch: deletedBatch,
                        timestamp: Date.now()
                    });
                    
                    // Limit undo stack to 10 items
                    if (this.undoStack.length > 10) {
                        this.undoStack.shift();
                    }
                    
                    // Remove any resize boxes
                    const resizeBoxes = this.editorSvgElement.querySelectorAll('.resize-box');
                    resizeBoxes.forEach(box => box.remove());
                    
                    // Clear selection
                    this.clearSelection();
                    
                    // Update layers list
                    this.updateLayers();
                },

                undoDelete() {
                    if (this.undoStack.length === 0) return;
                    
                    const lastDeleted = this.undoStack.pop();
                    
                    // Clear current selection
                    this.clearSelection();
                    
                    // Restore all elements in the batch
                    if (lastDeleted.batch) {
                        lastDeleted.batch.forEach(item => {
                            // Restore the element to its original position
                            if (item.nextSibling) {
                                item.parent.insertBefore(item.element, item.nextSibling);
                            } else {
                                item.parent.appendChild(item.element);
                            }
                            
                            // Make it draggable again if it's a group
                            if (item.element.tagName === 'g') {
                                this.makeGroupDraggable(item.element);
                            }
                            
                            // Add to selection
                            this.selectedElements.push(item.element);
                            if (this.editMode) {
                                item.element.style.outline = '2px solid #8b5cf6';
                            }
                        });
                    } else {
                        // Legacy support for old single-element undo format
                        if (lastDeleted.nextSibling) {
                            lastDeleted.parent.insertBefore(lastDeleted.element, lastDeleted.nextSibling);
                        } else {
                            lastDeleted.parent.appendChild(lastDeleted.element);
                        }
                        
                        if (lastDeleted.element.tagName === 'g') {
                            this.makeGroupDraggable(lastDeleted.element);
                        }
                        
                        this.selectedElements.push(lastDeleted.element);
                        if (this.editMode) {
                            lastDeleted.element.style.outline = '2px solid #8b5cf6';
                        }
                    }

                    if (!this.editMode) {
                        const moveGroups = this.selectedElements.filter((el) => el.tagName?.toLowerCase() === 'g');
                        this.selectMoveModeElements(moveGroups);
                    }
                    
                    // Update layers list
                    this.updateLayers();
                    this.syncTextEditorFromSelection();
                },

                resetEditor() {
                    if (this.editorSvgUrl) {
                        this.openEditorTab(this.editorSvgUrl);
                    }
                },

                saveEditorState() {
                    return false;
                },

                loadEditorStates() {
                    this.editorStates = [];
                },

                loadEditorStateById() {
                    return false;
                },

                deleteEditorStateById() {
                    return false;
                },

                importLogoToEditor(url) {
                    console.log('importLogoToEditor called with URL:', url);
                    console.log('editorSvgElement exists:', !!this.editorSvgElement);
                    
                    // Close import modal
                    this.showImportModal = false;
                    this.replaceTargetElement = null;
                    
                    // If no SVG is loaded yet, open editor tab with this SVG
                    if (!this.editorSvgElement) {
                        console.log('Opening editor tab with new SVG');
                        this.openEditorTab(url);
                    } else {
                        console.log('Adding vector to existing canvas');
                        // Add this vector to the existing canvas
                        this.addVectorToCanvas(url);
                    }
                },

                async addVectorToCanvas(url) {
                    console.log('addVectorToCanvas called with URL:', url);
                    try {
                        console.log('Fetching vector from URL...');
                        const response = await fetch(url);
                        const svgText = await response.text();
                        console.log('Vector fetched successfully, length:', svgText.length);
                        
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(svgText, 'image/svg+xml');
                        const importedSvg = doc.querySelector('svg');
                        console.log('Imported SVG element parsed:', !!importedSvg);
                        
                        if (!importedSvg) {
                            console.error('No SVG element found in imported document');
                            return;
                        }
                        
                        // First, extract and preserve style and defs elements at root level
                        const styles = Array.from(importedSvg.querySelectorAll('style'));
                        const defs = Array.from(importedSvg.querySelectorAll('defs'));
                        
                        // Move styles and defs to main SVG root (with unique IDs to avoid conflicts)
                        const timestamp = Date.now();
                        styles.forEach((style, idx) => {
                            const clonedStyle = style.cloneNode(true);
                            clonedStyle.setAttribute('id', `imported-style-${timestamp}-${idx}`);
                            this.editorSvgElement.appendChild(clonedStyle);
                        });
                        
                        defs.forEach((def, idx) => {
                            const clonedDef = def.cloneNode(true);
                            clonedDef.setAttribute('id', `imported-defs-${timestamp}-${idx}`);
                            this.editorSvgElement.appendChild(clonedDef);
                        });
                        
                        // Create a group element to hold all imported elements
                        const group = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                        const groupId = 'imported-' + timestamp;
                        group.setAttribute('id', groupId);
                        group.setAttribute('data-layer-name', 'Imported Vector');
                        
                        // Get viewBox for positioning
                        const viewBox = this.editorSvgElement.getAttribute('viewBox')?.split(' ') || [0, 0, 512, 512];
                        const canvasWidth = parseFloat(viewBox[2]);
                        const canvasHeight = parseFloat(viewBox[3]);
                        
                        // Get imported SVG dimensions
                        const importViewBox = importedSvg.getAttribute('viewBox')?.split(' ') || [0, 0, 100, 100];
                        const importWidth = parseFloat(importViewBox[2]);
                        const importHeight = parseFloat(importViewBox[3]);
                        
                        // Calculate scale to fit imported vector reasonably on canvas
                        const maxSize = Math.min(canvasWidth, canvasHeight) * 0.8; // 80% of canvas for large display
                        const scale = Math.min(maxSize / importWidth, maxSize / importHeight);
                        
                        // Position at center
                        const translateX = (canvasWidth - importWidth * scale) / 2;
                        const translateY = (canvasHeight - importHeight * scale) / 2;
                        
                        group.setAttribute('transform', `translate(${translateX}, ${translateY}) scale(${scale})`);
                        
                        // Remove white backgrounds before importing
                        this.removeWhiteBackgrounds(importedSvg, importViewBox);
                        
                        // Move all remaining children (excluding style/defs already moved) from imported SVG to group
                        while (importedSvg.firstChild) {
                            // Skip style and defs as we've already handled them
                            if (importedSvg.firstChild.tagName === 'style' || importedSvg.firstChild.tagName === 'defs') {
                                importedSvg.firstChild.remove();
                                continue;
                            }
                            group.appendChild(importedSvg.firstChild);
                        }
                        
                        // Add group to canvas (replace selected logo if requested)
                        const replaceTarget = this.replaceTargetElement;
                        if (replaceTarget && replaceTarget.parentNode) {
                            const targetTransform = replaceTarget.getAttribute('transform');
                            if (targetTransform) {
                                group.setAttribute('transform', targetTransform);
                            }
                            replaceTarget.parentNode.insertBefore(group, replaceTarget);
                            replaceTarget.remove();
                            this.replaceTargetElement = null;
                        } else {
                            this.editorSvgElement.appendChild(group);
                        }
                        console.log('Group added to canvas:', groupId);
                        
                        // Make the group draggable
                        this.makeGroupDraggable(group);
                        console.log('Group made draggable');
                        this.selectMoveModeGroup(group);
                        
                        // Switch to move mode and clear undo stack when importing
                        this.editMode = false;
                        this.undoStack = [];
                        
                        // Update element interactivity based on current mode
                        this.updateElementInteractivity();
                        this.updateLayers();
                        this.hideHoverMenu();
                        this.updateHoverMenuPosition();
                        console.log('Layers updated, import complete');
                        
                    } catch (error) {
                        console.error('Failed to add vector:', error);
                        alert('Failed to add vector to canvas');
                    }
                },

                removeWhiteBackgrounds(svgElement, viewBox) {
                    // Parse viewBox to get dimensions
                    const [vbX, vbY, vbWidth, vbHeight] = viewBox.map(v => parseFloat(v));
                    
                    // Helper to check if color is white/near-white
                    const isWhiteColor = (fill, style) => {
                        if (!fill && !style) return false;
                        
                        const fillLower = (fill || '').toLowerCase().trim();
                        const styleLower = (style || '').toLowerCase();
                        
                        // Check hex values
                        if (fillLower === 'white' || fillLower === '#fff' || fillLower === '#ffffff') return true;
                        
                        // Check RGB values (255, 255, 255) or very close to white
                        const rgbMatch = fillLower.match(/rgb\((\d+),?\s*(\d+),?\s*(\d+)\)/);
                        if (rgbMatch) {
                            const [_, r, g, b] = rgbMatch.map(v => parseInt(v));
                            // Consider it white if all values are >= 250
                            if (r >= 250 && g >= 250 && b >= 250) return true;
                        }
                        
                        // Check style attribute
                        if (styleLower.includes('fill:white') || 
                            styleLower.includes('fill:#fff') || 
                            styleLower.includes('fill: white') || 
                            styleLower.includes('fill: #fff') ||
                            styleLower.includes('fill:#ffffff') ||
                            styleLower.includes('fill: #ffffff')) {
                            return true;
                        }
                        
                        return false;
                    };
                    
                    // ONLY remove white rectangles that are CLEARLY full-page backgrounds
                    // Be very conservative to avoid removing design elements
                    const rects = svgElement.querySelectorAll('rect');
                    rects.forEach(rect => {
                        const x = parseFloat(rect.getAttribute('x') || 0);
                        const y = parseFloat(rect.getAttribute('y') || 0);
                        const width = parseFloat(rect.getAttribute('width') || 0);
                        const height = parseFloat(rect.getAttribute('height') || 0);
                        const fill = rect.getAttribute('fill');
                        const style = rect.getAttribute('style');
                        
                        // Only remove if it covers at least 98% of viewBox (very conservative)
                        const widthRatio = width / vbWidth;
                        const heightRatio = height / vbHeight;
                        const isFullPageBackground = (widthRatio >= 0.98 && heightRatio >= 0.98);
                        
                        // Must be positioned at or very near the origin
                        const isAtOrigin = (Math.abs(x - vbX) < 5 && Math.abs(y - vbY) < 5);
                        
                        // Must be the very first child element (z-index)
                        const parent = rect.parentElement;
                        const siblings = Array.from(parent.children);
                        const isFirstElement = siblings.indexOf(rect) === 0;
                        
                        // ALL conditions must be true
                        if (isWhiteColor(fill, style) && isFullPageBackground && isAtOrigin && isFirstElement) {
                            console.log('Removing full-page white background rect:', rect);
                            rect.remove();
                        }
                    });
                    
                    // Check paths for full-page white backgrounds
                    // Most paths are part of logo design (like letter interiors), but sometimes
                    // the background is rendered as a path instead of a rect
                    const paths = svgElement.querySelectorAll('path');
                    const parent = paths.length > 0 ? paths[0].parentElement : null;
                    if (parent) {
                        const siblings = Array.from(parent.children);
                        paths.forEach((path, index) => {
                            // Only check the very first path element
                            if (siblings.indexOf(path) !== 0) return;
                            
                            const fill = path.getAttribute('fill');
                            const style = path.getAttribute('style');
                            const d = path.getAttribute('d');
                            
                            if (!isWhiteColor(fill, style) || !d) return;
                            
                            // Check if the path describes a full-page rectangle
                            // Common patterns: "M 0 0 L 2048 0 L 2048 2048 L 0 2048 L 0 0 z"
                            // or "M 0,0 L width,0 L width,height L 0,height Z"
                            const rectPattern = /M\s*[\d.\-]+[\s,]+[\d.\-]+\s*L\s*[\d.\-]+[\s,]+[\d.\-]+\s*L\s*[\d.\-]+[\s,]+[\d.\-]+\s*L\s*[\d.\-]+[\s,]+[\d.\-]+\s*[LZ]/i;
                            
                            if (rectPattern.test(d)) {
                                // Parse the path to check if it covers the full viewBox
                                const coords = d.match(/[\d.\-]+/g);
                                if (coords && coords.length >= 8) {
                                    const x1 = parseFloat(coords[0]);
                                    const y1 = parseFloat(coords[1]);
                                    const x2 = parseFloat(coords[2]);
                                    const y2 = parseFloat(coords[3]);
                                    const x3 = parseFloat(coords[4]);
                                    const y3 = parseFloat(coords[5]);
                                    
                                    // Check if it's a rectangle from origin covering most of the viewBox
                                    const pathWidth = Math.max(x2, x3);
                                    const pathHeight = Math.max(y2, y3);
                                    const isAtOrigin = (Math.abs(x1 - vbX) < 5 && Math.abs(y1 - vbY) < 5);
                                    const coversFullPage = (pathWidth >= vbWidth * 0.98 && pathHeight >= vbHeight * 0.98);
                                    
                                    if (isAtOrigin && coversFullPage) {
                                        console.log('Removing full-page white background path:', path);
                                        path.remove();
                                    }
                                }
                            }
                        });
                    }
                    
                    // DO NOT remove polygons - they are also part of logo design
                    
                    // Only remove very large white circles that are clearly backgrounds
                    const circles = svgElement.querySelectorAll('circle, ellipse');
                    circles.forEach((circle, index) => {
                        const fill = circle.getAttribute('fill');
                        const style = circle.getAttribute('style');
                        
                        // Only check first element and only if it's VERY large
                        if (index === 0 && isWhiteColor(fill, style)) {
                            const r = parseFloat(circle.getAttribute('r') || circle.getAttribute('rx') || 0);
                            // Circle must be huge (90% of viewBox) to be considered a background
                            if (r > Math.min(vbWidth, vbHeight) * 0.9) {
                                console.log('Removing full-page white circle background:', circle);
                                circle.remove();
                            }
                        }
                    });
                    
                    // Remove any style or fill attributes set to white on the SVG itself
                    if (svgElement.style.backgroundColor) {
                        svgElement.style.backgroundColor = 'transparent';
                    }
                    if (svgElement.getAttribute('fill') === 'white' || 
                        svgElement.getAttribute('fill') === '#fff' ||
                        svgElement.getAttribute('fill') === '#ffffff') {
                        svgElement.removeAttribute('fill');
                    }
                },

                removeSvgBackground() {
                    if (!this.editorSvgElement) return;

                    const viewBoxValues = (this.editorSvgElement.getAttribute('viewBox') || '0 0 512 512')
                        .split(/\s+/)
                        .map((v) => parseFloat(v));
                    const vbX = Number.isFinite(viewBoxValues[0]) ? viewBoxValues[0] : 0;
                    const vbY = Number.isFinite(viewBoxValues[1]) ? viewBoxValues[1] : 0;
                    const vbWidth = Number.isFinite(viewBoxValues[2]) ? viewBoxValues[2] : 512;
                    const vbHeight = Number.isFinite(viewBoxValues[3]) ? viewBoxValues[3] : 512;

                    let removedCount = 0;

                    const parseNum = (value, fallback = 0) => {
                        const num = parseFloat(value);
                        return Number.isFinite(num) ? num : fallback;
                    };

                    const isInvisible = (el) => {
                        const opacity = parseNum(el.getAttribute('opacity'), 1);
                        const fillOpacity = parseNum(el.getAttribute('fill-opacity'), 1);
                        return opacity <= 0 || fillOpacity <= 0;
                    };

                    const removeIfCanvasSized = (el, x, y, width, height) => {
                        if (!el || isInvisible(el)) return false;
                        const widthRatio = width / vbWidth;
                        const heightRatio = height / vbHeight;
                        const nearOrigin = Math.abs(x - vbX) <= 8 && Math.abs(y - vbY) <= 8;
                        const fullPage = widthRatio >= 0.95 && heightRatio >= 0.95;
                        const hasFill = (el.getAttribute('fill') || '').toLowerCase() !== 'none';

                        if (nearOrigin && fullPage && hasFill) {
                            el.remove();
                            removedCount += 1;
                            return true;
                        }
                        return false;
                    };

                    const editorBg = this.editorSvgElement.querySelectorAll('[data-editor-bg="true"]');
                    editorBg.forEach((el) => {
                        el.remove();
                        removedCount += 1;
                    });

                    const rects = this.editorSvgElement.querySelectorAll('rect');
                    rects.forEach((rect) => {
                        const x = parseNum(rect.getAttribute('x'), vbX);
                        const y = parseNum(rect.getAttribute('y'), vbY);
                        const width = parseNum(rect.getAttribute('width'), 0);
                        const height = parseNum(rect.getAttribute('height'), 0);
                        removeIfCanvasSized(rect, x, y, width, height);
                    });

                    const circles = this.editorSvgElement.querySelectorAll('circle, ellipse');
                    circles.forEach((circle) => {
                        const cx = parseNum(circle.getAttribute('cx'), vbX + vbWidth / 2);
                        const cy = parseNum(circle.getAttribute('cy'), vbY + vbHeight / 2);
                        const rx = parseNum(circle.getAttribute('rx'), parseNum(circle.getAttribute('r'), 0));
                        const ry = parseNum(circle.getAttribute('ry'), parseNum(circle.getAttribute('r'), 0));
                        const x = cx - rx;
                        const y = cy - ry;
                        const width = rx * 2;
                        const height = ry * 2;
                        removeIfCanvasSized(circle, x, y, width, height);
                    });

                    // Check paths for full-page white backgrounds
                    const paths = this.editorSvgElement.querySelectorAll('path');
                    paths.forEach((path, index) => {
                        // Only check first few paths
                        if (index > 2) return;
                        
                        const fill = path.getAttribute('fill');
                        const d = path.getAttribute('d');
                        
                        if (!fill || !d) return;
                        
                        // Check if white
                        const fillLower = fill.toLowerCase().trim();
                        const isWhite = fillLower === 'white' || 
                                       fillLower === '#fff' || 
                                       fillLower === '#ffffff' ||
                                       fillLower.match(/rgb\(25[0-5],?\s*25[0-5],?\s*25[0-5]\)/);
                        
                        if (!isWhite) return;
                        
                        // Check if it's a full-page rectangle path
                        const rectPattern = /M\s*[\d.\-]+[\s,]+[\d.\-]+\s*L\s*[\d.\-]+[\s,]+[\d.\-]+\s*L\s*[\d.\-]+[\s,]+[\d.\-]+\s*L\s*[\d.\-]+[\s,]+[\d.\-]+\s*[LZ]/i;
                        if (rectPattern.test(d)) {
                            const coords = d.match(/[\d.\-]+/g);
                            if (coords && coords.length >= 8) {
                                const pathWidth = Math.max(parseFloat(coords[2]), parseFloat(coords[4]));
                                const pathHeight = Math.max(parseFloat(coords[3]), parseFloat(coords[5]));
                                const nearOrigin = Math.abs(parseFloat(coords[0]) - vbX) <= 8 && Math.abs(parseFloat(coords[1]) - vbY) <= 8;
                                const coversFullPage = (pathWidth >= vbWidth * 0.95 && pathHeight >= vbHeight * 0.95);
                                
                                if (nearOrigin && coversFullPage) {
                                    path.remove();
                                    removedCount += 1;
                                }
                            }
                        }
                    });

                    if (this.editorSvgElement.style.backgroundColor) {
                        this.editorSvgElement.style.backgroundColor = 'transparent';
                    }
                    if (this.editorSvgElement.getAttribute('fill') && this.editorSvgElement.getAttribute('fill') !== 'none') {
                        this.editorSvgElement.removeAttribute('fill');
                    }

                    this.updateLayers();
                    this.hideHoverMenu();
                    alert(removedCount > 0 ? `Removed ${removedCount} background element(s).` : 'No removable SVG background found.');
                },

                makeGroupDraggable(group) {
                    // Re-binding can happen after restore/undo/import. Tear down old handlers first.
                    if (typeof group.__destroyDraggable === 'function') {
                        group.__destroyDraggable();
                    }

                    let isDragging = false;
                    let isResizing = false;
                    let resizeHandle = null;
                    let startX, startY;
                    let currentTransform = { translateX: 0, translateY: 0, scale: 1 };
                    let resizeBox = null;
                    let isSelected = false;
                    
                    // Parse existing transform
                    const transform = group.getAttribute('transform');
                    if (transform) {
                        const parsed = this.parseGroupTransform(transform);
                        currentTransform.translateX = parsed.translateX;
                        currentTransform.translateY = parsed.translateY;
                        currentTransform.scale = parsed.scale;
                    }
                    
                    // Create resize handles
                    const createResizeBox = () => {
                        if (resizeBox) return resizeBox;
                        
                        const bbox = group.getBBox();
                        const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                        g.setAttribute('class', 'resize-box');
                        
                        // Apply the same transform as the group so the box follows it
                        g.setAttribute('transform', 
                            `translate(${currentTransform.translateX}, ${currentTransform.translateY}) scale(${currentTransform.scale})`
                        );
                        
                        // Border - no pointer events
                        const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                        rect.setAttribute('x', bbox.x - 5);
                        rect.setAttribute('y', bbox.y - 5);
                        rect.setAttribute('width', bbox.width + 10);
                        rect.setAttribute('height', bbox.height + 10);
                        rect.setAttribute('fill', 'none');
                        rect.setAttribute('stroke', '#3b82f6');
                        rect.setAttribute('stroke-width', '2');
                        rect.setAttribute('stroke-dasharray', '5,5');
                        rect.style.pointerEvents = 'none';
                        g.appendChild(rect);
                        
                        // Corner and edge handles
                        const handleSize = 12;
                        const handles = [
                            { x: bbox.x - 5, y: bbox.y - 5, cursor: 'nwse-resize', pos: 'nw' },
                            { x: bbox.x + bbox.width / 2 - 5, y: bbox.y - 5, cursor: 'ns-resize', pos: 'n' },
                            { x: bbox.x + bbox.width + 5, y: bbox.y - 5, cursor: 'nesw-resize', pos: 'ne' },
                            { x: bbox.x + bbox.width + 5, y: bbox.y + bbox.height / 2 - 5, cursor: 'ew-resize', pos: 'e' },
                            { x: bbox.x + bbox.width + 5, y: bbox.y + bbox.height + 5, cursor: 'nwse-resize', pos: 'se' },
                            { x: bbox.x + bbox.width / 2 - 5, y: bbox.y + bbox.height + 5, cursor: 'ns-resize', pos: 's' },
                            { x: bbox.x - 5, y: bbox.y + bbox.height + 5, cursor: 'nesw-resize', pos: 'sw' },
                            { x: bbox.x - 5, y: bbox.y + bbox.height / 2 - 5, cursor: 'ew-resize', pos: 'w' }
                        ];
                        
                        handles.forEach(h => {
                            const handle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                            handle.setAttribute('cx', h.x + handleSize / 2);
                            handle.setAttribute('cy', h.y + handleSize / 2);
                            handle.setAttribute('r', handleSize / 2);
                            handle.setAttribute('fill', '#3b82f6');
                            handle.setAttribute('stroke', 'white');
                            handle.setAttribute('stroke-width', '2');
                            handle.style.cursor = h.cursor;
                            handle.style.pointerEvents = 'all';
                            handle.dataset.position = h.pos;
                            
                            // Add event listeners directly to the handle
                            handle.addEventListener('mousedown', (e) => {
                                isResizing = true;
                                resizeHandle = h.pos;
                                const coords = getCoords(e);
                                startX = coords.x;
                                startY = coords.y;
                                e.stopPropagation();
                                e.preventDefault();
                            });
                            
                            g.appendChild(handle);
                        });
                        
                        group.parentNode.appendChild(g);
                        resizeBox = g;
                        return g;
                    };
                    
                    const updateResizeBox = () => {
                        if (!resizeBox || !isSelected) return;
                        
                        // Don't recreate during active resize/drag - just update on next frame
                        if (isResizing || isDragging) {
                            // Schedule recreation after the operation
                            return;
                        }
                        
                        resizeBox.remove();
                        resizeBox = null;
                        createResizeBox();
                        this.updateHoverMenuPosition();
                    };
                    
                    const showResizeBox = () => {
                        // Hide all other resize boxes first
                        const allResizeBoxes = this.editorSvgElement.querySelectorAll('.resize-box');
                        allResizeBoxes.forEach(box => box.remove());
                        
                        isSelected = true;
                        createResizeBox();
                        if (this.selectedElements.length === 1 && this.selectedElements[0] === group) {
                            this.updateHoverMenuPosition();
                        }
                    };
                    
                    const hideResizeBox = () => {
                        isSelected = false;
                        if (resizeBox) {
                            resizeBox.remove();
                            resizeBox = null;
                        }
                    };
                    
                    const getCoords = (e) => {
                        const svg = this.editorSvgElement;
                        const pt = svg.createSVGPoint();
                        pt.x = e.clientX || e.touches?.[0]?.clientX || 0;
                        pt.y = e.clientY || e.touches?.[0]?.clientY || 0;
                        return pt.matrixTransform(svg.getScreenCTM().inverse());
                    };
                    
                    const onStart = (e) => {
                        // Disable whole-vector dragging while in edit mode.
                        if (this.editMode) return;

                        // Only start drag if clicking within the group (includes all child elements)
                        const clickedElement = e.target;
                        
                        // Check if clicked element is within this group
                        let isWithinGroup = false;
                        if (group.contains(clickedElement)) {
                            isWithinGroup = true;
                        }
                        
                        if (!isWithinGroup) return;
                        
                        // If clicking on a resize handle, don't start dragging
                        if (clickedElement.closest('.resize-box')) return;
                        
                        // If in editGroupMode, don't interfere - let child elements handle their own dragging
                        if (this.editGroupMode && group === this.editingGroup) {
                            return;
                        }
                        
                        // Select this logo and show handles/menu
                        this.selectMoveModeGroup(group);
                        
                        isDragging = true;
                        group.style.cursor = 'grabbing';
                        
                        const coords = getCoords(e);
                        startX = coords.x;
                        startY = coords.y;
                        
                        e.stopPropagation();
                        e.preventDefault();
                    };
                    
                    const onMove = (e) => {
                        if (isResizing) {
                            const coords = getCoords(e);
                            const dx = coords.x - startX;
                            const dy = coords.y - startY;
                            
                            // Calculate scale change - more intuitive calculation
                            const distance = Math.sqrt(dx * dx + dy * dy);
                            const direction = (dx + dy) > 0 ? 1 : -1;
                            const scaleChange = (distance / 50) * direction * 0.1;
                            
                            currentTransform.scale = Math.max(0.1, Math.min(5, currentTransform.scale + scaleChange));
                            
                            requestAnimationFrame(() => {
                                const transformStr = `translate(${currentTransform.translateX}, ${currentTransform.translateY}) scale(${currentTransform.scale})`;
                                group.setAttribute('transform', transformStr);
                                if (resizeBox) {
                                    resizeBox.setAttribute('transform', transformStr);
                                }
                                if (this.selectedElements.length === 1 && this.selectedElements[0] === group) {
                                    this.updateHoverMenuPosition();
                                }
                            });
                            
                            startX = coords.x;
                            startY = coords.y;
                            e.preventDefault();
                            return;
                        }
                        
                        if (!isDragging) return;
                        
                        const coords = getCoords(e);
                        const dx = coords.x - startX;
                        const dy = coords.y - startY;
                        
                        currentTransform.translateX += dx;
                        currentTransform.translateY += dy;
                        
                        requestAnimationFrame(() => {
                            const transformStr = `translate(${currentTransform.translateX}, ${currentTransform.translateY}) scale(${currentTransform.scale})`;
                            group.setAttribute('transform', transformStr);
                            if (resizeBox) {
                                resizeBox.setAttribute('transform', transformStr);
                            }
                            if (this.selectedElements.length === 1 && this.selectedElements[0] === group) {
                                this.updateHoverMenuPosition();
                            }
                        });
                        
                        startX = coords.x;
                        startY = coords.y;
                        
                        e.preventDefault();
                    };
                    
                    const onEnd = () => {
                        const wasResizing = isResizing;
                        const wasDragging = isDragging;
                        
                        isDragging = false;
                        isResizing = false;
                        resizeHandle = null;
                        group.style.cursor = 'move';
                        
                        // Recreate resize box after operation completes
                        if ((wasResizing || wasDragging) && isSelected) {
                            updateResizeBox();
                            this.updateHoverMenuPosition();
                        }
                    };
                    
                    // Hide resize box when clicking outside
                    const onClickOutside = (e) => {
                        if (!isSelected) return;

                        // Keep selection active while interacting with the floating hover menu.
                        if (e.target.closest('[data-hover-menu="true"]')) return;

                        // Don't hide if clicking on a handle or the resize box itself
                        const isResizeBoxElement = e.target.closest('.resize-box');
                        if (isResizeBoxElement) return;
                        
                        // Hide if clicking outside the group
                        if (!group.contains(e.target)) {
                            if (!this.isSelecting) {
                                this.clearSelection();
                            } else {
                                hideResizeBox();
                            }
                        }
                    };
                    
                    group.__showResizeBox = showResizeBox;
                    group.__hideResizeBox = hideResizeBox;
                    group.__updateResizeBox = updateResizeBox;
                    group.style.cursor = 'move';
                    group.addEventListener('mousedown', onStart);
                    group.addEventListener('touchstart', onStart);
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('touchmove', onMove);
                    document.addEventListener('mouseup', onEnd);
                    document.addEventListener('touchend', onEnd);
                    document.addEventListener('mousedown', onClickOutside);

                    group.__destroyDraggable = () => {
                        hideResizeBox();
                        group.removeEventListener('mousedown', onStart);
                        group.removeEventListener('touchstart', onStart);
                        document.removeEventListener('mousemove', onMove);
                        document.removeEventListener('touchmove', onMove);
                        document.removeEventListener('mouseup', onEnd);
                        document.removeEventListener('touchend', onEnd);
                        document.removeEventListener('mousedown', onClickOutside);
                    };
                },

                async fetchUserLogos() {
                    this.loadingUserLogos = true;
                    try {
                        const response = await fetch('/domain-search/user-logos', {
                            method: 'GET',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            }
                        });

                        const data = await response.json();
                        if (data.success && data.logos) {
                            this.userLogos = data.logos;
                        }
                    } catch (error) {
                        console.error('Failed to fetch user logos:', error);
                    } finally {
                        this.loadingUserLogos = false;
                    }
                },

                async queueSimilarIdeasLookup() {
                    if (!this.logoDomain) return;

                    try {
                        await fetch('/domain-search/queue-similar-ideas', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            },
                            body: JSON.stringify({
                                domain: this.logoDomain,
                                prompt: this.logoPrompt
                            })
                        });

                        // Poll for results
                        setTimeout(() => this.fetchSimilarIdeas(), 3000);
                    } catch (err) {
                        console.error('Similar ideas queue error:', err);
                    }
                },

                async fetchSimilarIdeas() {
                    try {
                        const response = await fetch(`/domain-search/similar-ideas?domain=${encodeURIComponent(this.logoDomain)}`);
                        const data = await response.json();
                        
                        if (data.ideas && data.ideas.length > 0) {
                            this.similarIdeas = data.ideas;
                        } else {
                            // Keep polling if not ready
                            setTimeout(() => this.fetchSimilarIdeas(), 3000);
                        }
                    } catch (err) {
                        console.error('Fetch similar ideas error:', err);
                    }
                },

                loadFromSimilar(idea) {
                    this.logoDomain = idea.domain || this.logoDomain;
                    this.logoPrompt = idea.prompt || idea.query;
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            };
        }
    </script>
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
