<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AI Logo Lab - Toolbase</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-50">
    @if ($logoUser ?? false)
    <div x-data="logoGenerator()" x-init="init()" class="h-screen flex flex-col">
        <!-- Top Bar -->
        <div class="bg-white border-b border-gray-200">
            <div class="px-3 md:px-6 py-3 md:py-4 flex items-center justify-between gap-2">
                <div class="flex items-center gap-2 md:gap-3 min-w-0">
                    <!-- Hamburger Menu Toggle -->
                    <button 
                        @click="showLeftPanel = !showLeftPanel"
                        class="flex-shrink-0 p-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors text-gray-700"
                        :aria-label="showLeftPanel ? 'Hide sidebar' : 'Show sidebar'"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                    
                    <div class="flex-shrink-0 p-2 bg-violet-100 rounded-lg">
                        <svg class="w-5 h-5 md:w-6 md:h-6 text-violet-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 008 10.172V5L7 4z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h1 class="text-base md:text-xl font-bold text-gray-900 truncate">AI Logo Lab</h1>
                            <span class="hidden sm:inline-block px-2 py-1 bg-violet-100 text-violet-700 text-xs font-semibold rounded flex-shrink-0">EXPERIMENTAL</span>
                        </div>
                        <p class="hidden md:block text-sm text-gray-500">Full-screen studio workspace</p>
                    </div>
                    <button 
                        @click="showTextPromptPanel = !showTextPromptPanel"
                        class="hidden lg:flex ml-4 px-3 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors items-center gap-2 text-sm font-medium text-gray-700"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                        <span x-text="showTextPromptPanel ? 'Hide' : 'Edit Text & Prompt'"></span>
                    </button>
                </div>
                
                <!-- Cost Display & Generate Button -->
                <div class="flex items-center gap-2 md:gap-4">
                    <div class="hidden md:block text-right">
                        <div class="text-sm text-gray-500">Estimated Cost</div>
                        <div class="text-lg font-bold text-gray-900" x-text="'$' + logoPrice.toFixed(2)"></div>
                    </div>
                    <button 
                        @click="generateLogo()"
                        :disabled="generating || !logoDomain"
                        class="px-3 md:px-6 py-2 md:py-3 bg-violet-600 hover:bg-violet-700 disabled:bg-gray-300 disabled:cursor-not-allowed text-white text-sm md:text-base font-semibold rounded-lg transition-colors shadow-lg whitespace-nowrap"
                    >
                        <span x-show="!generating" class="hidden md:inline">Generate Logos</span>
                        <span x-show="!generating" class="md:hidden">Generate</span>
                        <span x-show="generating" class="flex items-center gap-2">
                            <svg class="animate-spin h-4 w-4 md:h-5 md:w-5" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span class="hidden md:inline">Generating...</span>
                        </span>
                    </button>
                </div>
            </div>

            <!-- Expandable Text & Prompt Panel -->
            <div x-show="showTextPromptPanel" x-cloak x-transition class="border-t border-gray-200 bg-gray-50 px-6 py-4">
                <div class="max-w-4xl mx-auto grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Logo Text Input -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-900 mb-2">Logo Text</label>
                        <input 
                            type="text" 
                            x-model="logoDomain"
                            @input="fetchLogoPrice()"
                            placeholder="e.g., TechStart, CloudSync, etc."
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-violet-500 focus:border-transparent bg-white"
                        >
                    </div>

                    <!-- Prompt -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-900 mb-2">Custom Prompt (Optional)</label>
                        <textarea 
                            x-model="logoPrompt"
                            @input="fetchLogoPrice()"
                            rows="3"
                            placeholder="Describe your logo style, colors, mood..."
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-violet-500 focus:border-transparent resize-none bg-white"
                        ></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex flex-1 overflow-hidden relative">
            <!-- Mobile Backdrop -->
            <div 
                x-show="showLeftPanel" 
                @click="showLeftPanel = false"
                x-transition:enter="transition-opacity ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition-opacity ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="lg:hidden fixed inset-0 bg-black bg-opacity-50 z-10"
                x-cloak
            ></div>
            
            <!-- Left Sidebar -->
            <div 
                x-show="showLeftPanel"
                x-transition:enter="transition-transform ease-out duration-200"
                x-transition:enter-start="-translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition-transform ease-in duration-150"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="-translate-x-full"
                class="w-80 lg:w-96 bg-white border-r border-gray-200 overflow-y-auto fixed lg:relative inset-y-0 left-0 z-20 lg:z-0"
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
                        <h3 class="text-sm font-semibold text-gray-900 mb-3">AI Model</h3>
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
                                        <p class="text-xs text-gray-500 mt-1">$0.003/image</p>
                                    </div>
                                </div>
                            </button>

                            <!-- Balanced: Ray -->
                            <button 
                                @click="selectModel('recraft')"
                                :class="selectedModel === 'recraft' ? 'ring-2 ring-violet-500 bg-violet-50' : 'hover:bg-gray-50'"
                                class="w-full p-4 border border-gray-200 rounded-lg text-left transition-all"
                            >
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="font-semibold text-gray-900">Ray</span>
                                            <span class="px-2 py-0.5 bg-violet-100 text-violet-700 text-xs font-medium rounded">Balanced</span>
                                        </div>
                                        <p class="text-sm text-gray-600 mt-1">Best quality-to-speed ratio</p>
                                        <p class="text-xs text-gray-500 mt-1">$0.005/image</p>
                                    </div>
                                </div>
                            </button>

                            <!-- Pro: Cosmo -->
                            <button 
                                @click="selectModel('dalle')"
                                :class="selectedModel === 'dalle' ? 'ring-2 ring-amber-500 bg-amber-50' : 'hover:bg-gray-50'"
                                class="w-full p-4 border border-gray-200 rounded-lg text-left transition-all"
                            >
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="font-semibold text-gray-900">Cosmo</span>
                                            <span class="px-2 py-0.5 bg-amber-100 text-amber-700 text-xs font-medium rounded">Pro</span>
                                        </div>
                                        <p class="text-sm text-gray-600 mt-1">Highest quality, complex prompts</p>
                                        <p class="text-xs text-gray-500 mt-1">$0.040/image</p>
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
                            </div>
                            <svg class="w-4 h-4 text-gray-400 group-hover:text-gray-500 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                        </button>
                    </div>

                    <!-- Color Palette -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-900 mb-3">Color Palette</label>
                        <button type="button" @click="logoColorPalette = 'none'"
                            class="w-full mb-2 rounded-xl border-2 p-2 transition-all flex items-center gap-2"
                            :class="logoColorPalette === 'none' ? 'border-violet-500 ring-2 ring-violet-200 bg-violet-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="flex-1 h-6 rounded-md bg-gray-100 flex items-center justify-center">
                                <span class="text-xs font-medium text-gray-400">AI Picks</span>
                            </div>
                            <div class="text-xs font-medium" :class="logoColorPalette === 'none' ? 'text-violet-700' : 'text-gray-500'">None</div>
                        </button>
                        <div class="grid grid-cols-2 gap-2">
                            <template x-for="p in colorPalettes" :key="p.id">
                                <button type="button" @click="logoColorPalette = p.id"
                                    class="rounded-xl border-2 p-2 transition-all"
                                    :class="logoColorPalette === p.id ? 'border-violet-500 ring-2 ring-violet-200' : 'border-gray-200 hover:border-gray-300'">
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
                                @click="backgroundColor = 'white'"
                                :class="backgroundColor === 'white' ? 'ring-2 ring-violet-500' : ''"
                                class="flex-1 px-4 py-3 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
                            >
                                White
                            </button>
                            <button 
                                @click="backgroundColor = 'transparent'"
                                :class="backgroundColor === 'transparent' ? 'ring-2 ring-violet-500' : ''"
                                class="flex-1 px-4 py-3 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
                            >
                                Transparent
                            </button>
                        </div>
                    </div>

                    <!-- Shape Container -->
                    <div x-show="selectedModel === 'recraft'">
                        <label class="block text-sm font-semibold text-gray-900 mb-2">Shape</label>
                        <select 
                            x-model="shapeContainer"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-violet-500"
                        >
                            <option value="">None</option>
                            <option value="circle">Circle</option>
                            <option value="square">Square</option>
                        </select>
                    </div>

                    <!-- Detail Level -->
                    <div x-show="selectedModel === 'recraft'">
                        <label class="block text-sm font-semibold text-gray-900 mb-2">Detail Level</label>
                        <select 
                            x-model="detailLevel"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-violet-500"
                        >
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                        </select>
                    </div>

                    <!-- Number of Logos -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-900 mb-2">Number of Logos</label>
                        <div class="flex gap-2">
                            <template x-for="num in [1,2,3,4]">
                                <button 
                                    @click="logoCount = num; fetchLogoPrice()"
                                    :class="logoCount === num ? 'bg-violet-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                                    class="flex-1 px-4 py-3 border border-gray-300 rounded-lg font-medium transition-colors"
                                    x-text="num"
                                ></button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Canvas Area -->
            <div class="flex-1 overflow-y-auto bg-gray-50">
                <div class="p-8">
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
                    <div x-show="logoImages.length > 0" class="space-y-6">
                        <h2 class="text-2xl font-bold text-gray-900">Generated Logos</h2>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-6">
                            <template x-for="(image, index) in logoImages" :key="index">
                                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden hover:shadow-lg transition-shadow">
                                    <!-- Image -->
                                    <div class="aspect-square bg-gray-100 relative group cursor-pointer" @click="zoomImage(image.url)">
                                        <img :src="image.url" :alt="'Logo ' + (index + 1)" class="w-full h-full object-contain p-4">
                                        <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-10 transition-opacity flex items-center justify-center">
                                            <svg class="w-12 h-12 text-white opacity-0 group-hover:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v6m3-3H7"/>
                                            </svg>
                                        </div>
                                    </div>

                                    <!-- Actions -->
                                    <div class="p-4 space-y-2">
                                        <div class="grid grid-cols-2 gap-2">
                                            <button 
                                                @click="saveLogo(image.url)"
                                                class="px-3 py-2 bg-violet-600 hover:bg-violet-700 text-white text-sm font-medium rounded-lg transition-colors"
                                            >
                                                Save
                                            </button>
                                            <button 
                                                @click="convertToSvg(image.url)"
                                                class="px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors"
                                            >
                                                SVG
                                            </button>
                                        </div>
                                        <div class="grid grid-cols-2 gap-2">
                                            <button 
                                                @click="openInEditor(image.url)"
                                                class="px-3 py-2 bg-gray-700 hover:bg-gray-800 text-white text-sm font-medium rounded-lg transition-colors"
                                            >
                                                Editor
                                            </button>
                                            <button 
                                                @click="removeBackground(image.url)"
                                                class="px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-lg transition-colors"
                                            >
                                                Remove BG
                                            </button>
                                        </div>
                                        <button 
                                            x-show="image.seed"
                                            @click="useSeed(image.seed)"
                                            class="w-full px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-lg transition-colors"
                                        >
                                            Use Seed
                                        </button>
                                        <button 
                                            @click="useAsPrompt(image.url)"
                                            class="w-full px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-lg transition-colors"
                                        >
                                            Use as Prompt
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Similar Ideas Section -->
                    <div x-show="similarIdeas.length > 0" class="mt-12 space-y-6">
                        <h2 class="text-2xl font-bold text-gray-900">Similar Ideas</h2>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-6">
                            <template x-for="idea in similarIdeas" :key="idea.id">
                                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden hover:shadow-lg transition-shadow">
                                    <div class="aspect-square bg-gray-100 relative cursor-pointer" @click="zoomImage(idea.prompt_outputs[0].url)">
                                        <img :src="idea.prompt_outputs[0].url" :alt="idea.query" class="w-full h-full object-contain p-4">
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
                    <div x-show="logoImages.length === 0 && !generating" class="flex flex-col items-center justify-center py-24 text-center">
                        <div class="p-6 bg-violet-100 rounded-full mb-6">
                            <svg class="w-16 h-16 text-violet-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 008 10.172V5L7 4z"/>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-900 mb-2">Ready to Create</h3>
                        <p class="text-gray-600 max-w-md">Configure your logo settings in the sidebar and click Generate to create stunning AI-powered logos.</p>
                    </div>

                    <!-- Generating State -->
                    <div x-show="generating" x-cloak class="flex flex-col items-center justify-center py-24 text-center">
                        <svg class="animate-spin h-16 w-16 text-violet-600 mb-6" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <h3 class="text-2xl font-bold text-gray-900 mb-2">Generating Your Logos...</h3>
                        <p class="text-gray-600">This may take a few moments</p>
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
                    <!-- DALL-E styles -->
                    <div x-show="selectedModel === 'dalle'">
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            <button type="button" @click="selectStyle('professional')"
                                class="group rounded-xl border-2 p-3 transition-all text-center"
                                :class="logoStyle === 'professional' ? 'border-violet-500 ring-2 ring-violet-200 bg-violet-50' : 'border-gray-200 hover:border-gray-300'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'professional' ? 'text-violet-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'professional' ? 'text-violet-700' : 'text-gray-600'">Professional</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">Clean & modern</div>
                            </button>
                            <button type="button" @click="selectStyle('fantasy')"
                                class="group rounded-xl border-2 p-3 transition-all text-center"
                                :class="logoStyle === 'fantasy' ? 'border-violet-500 ring-2 ring-violet-200 bg-violet-50' : 'border-gray-200 hover:border-gray-300'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'fantasy' ? 'text-violet-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'fantasy' ? 'text-violet-700' : 'text-gray-600'">Fantasy</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">Magical & ornate</div>
                            </button>
                            <button type="button" @click="selectStyle('future')"
                                class="group rounded-xl border-2 p-3 transition-all text-center"
                                :class="logoStyle === 'future' ? 'border-violet-500 ring-2 ring-violet-200 bg-violet-50' : 'border-gray-200 hover:border-gray-300'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'future' ? 'text-violet-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'future' ? 'text-violet-700' : 'text-gray-600'">Future</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">Techy & sci-fi</div>
                            </button>
                            <button type="button" @click="selectStyle('retro')"
                                class="group rounded-xl border-2 p-3 transition-all text-center"
                                :class="logoStyle === 'retro' ? 'border-violet-500 ring-2 ring-violet-200 bg-violet-50' : 'border-gray-200 hover:border-gray-300'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'retro' ? 'text-violet-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'retro' ? 'text-violet-700' : 'text-gray-600'">Retro</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">Vintage & classic</div>
                            </button>
                            <button type="button" @click="selectStyle('greetingcard')"
                                class="group rounded-xl border-2 p-3 transition-all text-center"
                                :class="logoStyle === 'greetingcard' ? 'border-violet-500 ring-2 ring-violet-200 bg-violet-50' : 'border-gray-200 hover:border-gray-300'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'greetingcard' ? 'text-violet-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'greetingcard' ? 'text-violet-700' : 'text-gray-600'">Greeting Card</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">Watercolor & gouache</div>
                            </button>
                            <button type="button" @click="selectStyle('custom')"
                                class="col-span-2 sm:col-span-3 group rounded-xl border-2 p-3 transition-all text-center"
                                :class="logoStyle === 'custom' ? 'border-purple-500 ring-2 ring-purple-200 bg-purple-50' : 'border-gray-200 hover:border-gray-300'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'custom' ? 'text-purple-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"></path></svg>
                                </div>
                                <div class="text-xs font-semibold" :class="logoStyle === 'custom' ? 'text-purple-700' : 'text-gray-600'">Custom Prompt</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">Write your own full prompt</div>
                            </button>
                        </div>
                        <div class="mt-5 mb-4">
                            <div class="w-full h-px bg-gradient-to-r from-transparent via-violet-400/70 to-transparent"></div>
                            <div class="text-center -mt-3">
                                <span class="inline-flex px-3 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider bg-white text-violet-600">Dalle3 specific styles</span>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            <button type="button" @click="selectStyle('chrome')"
                                class="group rounded-xl border-2 p-2 transition-all text-center"
                                :class="logoStyle === 'chrome' ? 'border-violet-500 ring-2 ring-violet-200 bg-violet-50' : 'border-gray-200 hover:border-gray-300'">
                                <div class="aspect-square rounded-lg overflow-hidden bg-gray-100 mb-2">
                                    <img src="/images/chrome-preview.svg" alt="Chrome style" class="w-full h-full object-cover" />
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'chrome' ? 'text-violet-700' : 'text-gray-600'">Chrome (Chome)</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">3D metallic render</div>
                                <span class="inline-block mt-1 px-1.5 py-0.5 rounded text-[9px] font-semibold bg-amber-100 text-amber-700">Icon Only</span>
                            </button>
                            <button type="button" @click="selectStyle('dotmatrix')"
                                class="group rounded-xl border-2 p-3 transition-all text-center"
                                :class="logoStyle === 'dotmatrix' ? 'border-violet-500 ring-2 ring-violet-200 bg-violet-50' : 'border-gray-200 hover:border-gray-300'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === 'dotmatrix' ? 'text-violet-600' : 'text-gray-400'" fill="currentColor" viewBox="0 0 24 24"><circle cx="6" cy="6" r="1.5"/><circle cx="12" cy="6" r="1.5"/><circle cx="18" cy="6" r="1.5"/><circle cx="6" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="18" cy="12" r="1.5"/><circle cx="6" cy="18" r="1.5"/><circle cx="12" cy="18" r="1.5"/><circle cx="18" cy="18" r="1.5"/></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === 'dotmatrix' ? 'text-violet-700' : 'text-gray-600'">Dot Matrix</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">Stipple art</div>
                                <span class="inline-block mt-1 px-1.5 py-0.5 rounded text-[9px] font-semibold bg-amber-100 text-amber-700">Icon Only</span>
                            </button>
                            <button type="button" @click="selectStyle('8bit')"
                                class="group rounded-xl border-2 p-3 transition-all text-center"
                                :class="logoStyle === '8bit' ? 'border-violet-500 ring-2 ring-violet-200 bg-violet-50' : 'border-gray-200 hover:border-gray-300'">
                                <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                    <svg class="w-6 h-6" :class="logoStyle === '8bit' ? 'text-violet-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"></path></svg>
                                </div>
                                <div class="text-xs font-semibold"
                                    :class="logoStyle === '8bit' ? 'text-violet-700' : 'text-gray-600'">8-Bit</div>
                                <div class="text-[10px] text-gray-400 mt-0.5">Fantasy RPG</div>
                            </button>
                        </div>
                    </div>

                    <!-- Flux/Recraft styles -->
                    <div x-show="selectedModel !== 'dalle'" class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        <button type="button" @click="selectStyle('professional')"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'professional' ? 'border-violet-500 ring-2 ring-violet-200 bg-violet-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                <svg class="w-6 h-6" :class="logoStyle === 'professional' ? 'text-violet-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'professional' ? 'text-violet-700' : 'text-gray-600'">Professional</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Clean & modern</div>
                        </button>
                        <button type="button" @click="selectStyle('fantasy')"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'fantasy' ? 'border-violet-500 ring-2 ring-violet-200 bg-violet-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                <svg class="w-6 h-6" :class="logoStyle === 'fantasy' ? 'text-violet-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"></path></svg>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'fantasy' ? 'text-violet-700' : 'text-gray-600'">Fantasy</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Magical & ornate</div>
                        </button>
                        <button type="button" @click="selectStyle('future')"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'future' ? 'border-violet-500 ring-2 ring-violet-200 bg-violet-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                <svg class="w-6 h-6" :class="logoStyle === 'future' ? 'text-violet-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"></path></svg>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'future' ? 'text-violet-700' : 'text-gray-600'">Future</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Techy & sci-fi</div>
                        </button>
                        <button type="button" @click="selectStyle('retro')"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'retro' ? 'border-violet-500 ring-2 ring-violet-200 bg-violet-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                <svg class="w-6 h-6" :class="logoStyle === 'retro' ? 'text-violet-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'retro' ? 'text-violet-700' : 'text-gray-600'">Retro</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Vintage & classic</div>
                        </button>
                        <button type="button" @click="selectStyle('greetingcard')"
                            class="group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'greetingcard' ? 'border-violet-500 ring-2 ring-violet-200 bg-violet-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                <svg class="w-6 h-6" :class="logoStyle === 'greetingcard' ? 'text-violet-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"></path></svg>
                            </div>
                            <div class="text-xs font-semibold"
                                :class="logoStyle === 'greetingcard' ? 'text-violet-700' : 'text-gray-600'">Greeting Card</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Watercolor & gouache</div>
                        </button>
                        <button type="button" @click="selectStyle('custom')"
                            class="col-span-2 sm:col-span-3 group rounded-xl border-2 p-3 transition-all text-center"
                            :class="logoStyle === 'custom' ? 'border-purple-500 ring-2 ring-purple-200 bg-purple-50' : 'border-gray-200 hover:border-gray-300'">
                            <div class="w-10 h-10 mx-auto mb-2 rounded-lg bg-gray-100 flex items-center justify-center">
                                <svg class="w-6 h-6" :class="logoStyle === 'custom' ? 'text-purple-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"></path></svg>
                            </div>
                            <div class="text-xs font-semibold" :class="logoStyle === 'custom' ? 'text-purple-700' : 'text-gray-600'">Custom Prompt</div>
                            <div class="text-[10px] text-gray-400 mt-0.5">Write your own full prompt</div>
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
                logoStyle: 'professional',
                logoColorPalette: 'none',
                showTextPromptPanel: false,
                showLeftPanel: window.innerWidth >= 1024,
                logoCustomColors: ['#1e3a5f', '#d4af37', '#333333'],
                backgroundColor: 'white',
                shapeContainer: '',
                detailLevel: 'medium',
                seed: null,

                // State
                generating: false,
                logoImages: [],
                logoPrice: 0,
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

                init() {
                    this.fetchLogoPrice();
                    this.fetchSavedPalettes();
                },

                selectModel(model) {
                    this.selectedModel = model;
                    this.fetchLogoPrice();
                },

                getSelectedPaletteColors() {
                    if (this.logoColorPalette === 'none') return null;
                    if (this.logoColorPalette === 'custom') return this.logoCustomColors;
                    const p = this.colorPalettes.find(p => p.id === this.logoColorPalette);
                    return p ? p.colors : null;
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
                    const labels = { chrome: 'Chrome', professional: 'Professional', fantasy: 'Fantasy', future: 'Future', retro: 'Retro', '8bit': '8-Bit', dotmatrix: 'Dot Matrix', greetingcard: 'Greeting Card', custom: 'Custom Prompt' };
                    return labels[this.logoStyle] || 'Professional';
                },

                selectStyle(style) {
                    this.logoStyle = style;
                    this.showStyleModal = false;
                    this.fetchLogoPrice();
                },

                async fetchLogoPrice() {
                    if (!this.logoDomain) {
                        this.logoPrice = 0;
                        return;
                    }

                    try {
                        const response = await fetch('/domain-search/estimate-logo-price', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            },
                            body: JSON.stringify({
                                model: this.selectedModel,
                                count: this.logoCount,
                                domain: this.logoDomain,
                                prompt: this.logoPrompt
                            })
                        });

                        const data = await response.json();
                        this.logoPrice = data.price || 0;
                        if (data.credit_balance !== undefined) {
                            this.creditBalance = parseFloat(data.credit_balance);
                        }
                    } catch (err) {
                        console.error('Price estimate error:', err);
                    }
                },

                async generateLogo() {
                    if (!this.logoDomain || this.generating) return;

                    this.generating = true;
                    this.error = null;
                    this.logoImages = [];

                    try {
                        const payload = {
                            model: this.selectedModel,
                            domain: this.logoDomain,
                            prompt: this.logoPrompt,
                            style: this.logoStyle,
                            count: this.logoCount,
                            color_palette: this.logoColorPalette !== 'none' ? this.getSelectedPaletteColors() : null,
                            background: this.backgroundColor,
                            shape_container: this.shapeContainer,
                            detail_level: this.detailLevel,
                            seed: this.seed
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
                            throw new Error('Generation failed');
                        }

                        const data = await response.json();
                        
                        if (data.credit_balance !== undefined) {
                            this.creditBalance = parseFloat(data.credit_balance);
                        }

                        if (data.error) {
                            this.error = data.error;
                        } else {
                            this.logoImages = data.images || [];
                            this.queueSimilarIdeasLookup();
                        }
                    } catch (err) {
                        this.error = err.message || 'An error occurred while generating logos';
                    } finally {
                        this.generating = false;
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
                            // Add the new image to the grid
                            this.logoImages.push({ url: data.url });
                        }
                    } catch (err) {
                        console.error('Background removal error:', err);
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
