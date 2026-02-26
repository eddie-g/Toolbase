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
        <div class="bg-white border-b border-gray-200 px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-violet-100 rounded-lg">
                        <svg class="w-6 h-6 text-violet-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 008 10.172V5L7 4z"/>
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-xl font-bold text-gray-900">AI Logo Lab</h1>
                            <span class="px-2 py-1 bg-violet-100 text-violet-700 text-xs font-semibold rounded">EXPERIMENTAL</span>
                        </div>
                        <p class="text-sm text-gray-500">Full-screen studio workspace</p>
                    </div>
                </div>
                
                <!-- Cost Display -->
                <div class="flex items-center gap-4">
                    <div class="text-right">
                        <div class="text-sm text-gray-500">Estimated Cost</div>
                        <div class="text-lg font-bold text-gray-900" x-text="'$' + logoPrice.toFixed(2)"></div>
                    </div>
                    <button 
                        @click="generateLogo()"
                        :disabled="generating || !logoDomain"
                        class="px-6 py-3 bg-violet-600 hover:bg-violet-700 disabled:bg-gray-300 disabled:cursor-not-allowed text-white font-semibold rounded-lg transition-colors shadow-lg"
                    >
                        <span x-show="!generating">Generate Logos</span>
                        <span x-show="generating" class="flex items-center gap-2">
                            <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Generating...
                        </span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex flex-1 overflow-hidden">
            <!-- Left Sidebar -->
            <div class="w-96 bg-white border-r border-gray-200 overflow-y-auto">
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
                            <!-- Fast: Flux Schnell -->
                            <button 
                                @click="selectModel('flux')"
                                :class="selectedModel === 'flux' ? 'ring-2 ring-blue-500 bg-blue-50' : 'hover:bg-gray-50'"
                                class="w-full p-4 border border-gray-200 rounded-lg text-left transition-all"
                            >
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="font-semibold text-gray-900">Fast</span>
                                            <span class="px-2 py-0.5 bg-blue-100 text-blue-700 text-xs font-medium rounded">Flux Schnell</span>
                                        </div>
                                        <p class="text-sm text-gray-600 mt-1">Quick iterations, good quality</p>
                                        <p class="text-xs text-gray-500 mt-1">$0.003/image</p>
                                    </div>
                                </div>
                            </button>

                            <!-- Balanced: Recraft v3 -->
                            <button 
                                @click="selectModel('recraft')"
                                :class="selectedModel === 'recraft' ? 'ring-2 ring-violet-500 bg-violet-50' : 'hover:bg-gray-50'"
                                class="w-full p-4 border border-gray-200 rounded-lg text-left transition-all"
                            >
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="font-semibold text-gray-900">Balanced</span>
                                            <span class="px-2 py-0.5 bg-violet-100 text-violet-700 text-xs font-medium rounded">Recraft v3</span>
                                        </div>
                                        <p class="text-sm text-gray-600 mt-1">Best quality-to-speed ratio</p>
                                        <p class="text-xs text-gray-500 mt-1">$0.005/image</p>
                                    </div>
                                </div>
                            </button>

                            <!-- Pro: DALL-E 3 -->
                            <button 
                                @click="selectModel('dalle')"
                                :class="selectedModel === 'dalle' ? 'ring-2 ring-amber-500 bg-amber-50' : 'hover:bg-gray-50'"
                                class="w-full p-4 border border-gray-200 rounded-lg text-left transition-all"
                            >
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="font-semibold text-gray-900">Pro</span>
                                            <span class="px-2 py-0.5 bg-amber-100 text-amber-700 text-xs font-medium rounded">DALL-E 3</span>
                                        </div>
                                        <p class="text-sm text-gray-600 mt-1">Highest quality, complex prompts</p>
                                        <p class="text-xs text-gray-500 mt-1">$0.040/image</p>
                                    </div>
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- Logo Text Input -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-900 mb-2">Logo Text</label>
                        <input 
                            type="text" 
                            x-model="logoDomain"
                            @input="fetchLogoPrice()"
                            placeholder="e.g., TechStart, CloudSync, etc."
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-violet-500 focus:border-transparent"
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
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-violet-500 focus:border-transparent resize-none"
                        ></textarea>
                    </div>

                    <!-- Style -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-900 mb-2">Style</label>
                        <button 
                            @click="showStyleModal = true"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg text-left hover:bg-gray-50 flex items-center justify-between"
                        >
                            <span x-text="logoStyle || 'Select a style'"></span>
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
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
        <div x-show="showStyleModal" x-cloak class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4" @click.self="showStyleModal = false">
            <div class="bg-white rounded-xl shadow-2xl max-w-4xl w-full max-h-[80vh] overflow-y-auto" @click.stop>
                <div class="sticky top-0 bg-white border-b border-gray-200 px-6 py-4 flex items-center justify-between">
                    <h3 class="text-xl font-bold text-gray-900">Choose a Style</h3>
                    <button @click="showStyleModal = false" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                
                <div class="p-6">
                    <!-- DALL-E Styles -->
                    <div x-show="selectedModel === 'dalle'" class="space-y-4">
                        <h4 class="font-semibold text-gray-900">DALL-E Styles</h4>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                            <template x-for="style in dalleStyles" :key="style">
                                <button 
                                    @click="selectStyle(style)"
                                    :class="logoStyle === style ? 'ring-2 ring-violet-600 bg-violet-50' : 'hover:bg-gray-50'"
                                    class="px-4 py-3 border border-gray-200 rounded-lg text-left transition-all"
                                >
                                    <span class="font-medium text-gray-900" x-text="style"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- Flux/Recraft Styles -->
                    <div x-show="selectedModel !== 'dalle'" class="space-y-4">
                        <h4 class="font-semibold text-gray-900">Logo Styles</h4>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                            <template x-for="style in fluxRecraftStyles" :key="style">
                                <button 
                                    @click="selectStyle(style)"
                                    :class="logoStyle === style ? 'ring-2 ring-violet-600 bg-violet-50' : 'hover:bg-gray-50'"
                                    class="px-4 py-3 border border-gray-200 rounded-lg text-left transition-all"
                                >
                                    <span class="font-medium text-gray-900" x-text="style"></span>
                                </button>
                            </template>
                        </div>

                        <h4 class="font-semibold text-gray-900 mt-6">Special Effects</h4>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                            <template x-for="style in ['chrome', 'dotmatrix', '8bit']" :key="style">
                                <button 
                                    @click="selectStyle(style)"
                                    :class="logoStyle === style ? 'ring-2 ring-violet-600 bg-violet-50' : 'hover:bg-gray-50'"
                                    class="px-4 py-3 border border-gray-200 rounded-lg text-left transition-all"
                                >
                                    <span class="font-medium text-gray-900" x-text="style"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- None Option -->
                    <div class="mt-6">
                        <button 
                            @click="selectStyle(null)"
                            class="w-full px-4 py-3 border-2 border-dashed border-gray-300 rounded-lg text-gray-600 hover:bg-gray-50 transition-all"
                        >
                            No specific style
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
                logoStyle: null,
                logoColorPalette: 'none',
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
                dalleStyles: [
                    'minimalist', 'geometric', 'gradient', 'abstract', 'modern', 'vintage',
                    'hand-drawn', 'mascot', 'lettermark', 'emblem', 'badge', 'line art',
                    'flat design', 'isometric', '3D', 'neon', 'retro', 'professional'
                ],
                fluxRecraftStyles: [
                    'minimalist', 'geometric', 'abstract', 'modern', 'vintage',
                    'mascot', 'lettermark', 'emblem', 'badge', 'line art',
                    'flat design', 'isometric', '3D', 'neon', 'retro'
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
