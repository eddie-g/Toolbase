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
@php($logoUser = auth()->user() ?? auth('admin')->user())
<body class="bg-gray-50">
    @if ($logoUser)
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
                        <label class="block text-sm font-semibold text-gray-900 mb-2">Color Palette</label>
                        <div class="grid grid-cols-6 gap-2">
                            <template x-for="color in availableColors" :key="color">
                                <button 
                                    @click="toggleColor(color)"
                                    :style="'background-color: ' + color"
                                    :class="selectedColors.includes(color) ? 'ring-2 ring-offset-2 ring-violet-500' : ''"
                                    class="w-10 h-10 rounded-lg hover:scale-110 transition-transform"
                                ></button>
                            </template>
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
        @guest
        <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
            <div class="bg-white rounded-xl shadow-2xl max-w-md w-full p-8 text-center">
                <svg class="w-16 h-16 text-violet-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
                <h3 class="text-2xl font-bold text-gray-900 mb-2">Login Required</h3>
                <p class="text-gray-600 mb-6">Please log in to access the AI Logo Lab</p>
                <a href="/login" class="inline-block px-6 py-3 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-lg transition-colors">
                    Go to Login
                </a>
            </div>
        </div>
        @endguest
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
                selectedColors: [],
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

                // Available options
                availableColors: [
                    '#FF0000', '#FF7F00', '#FFFF00', '#00FF00', '#0000FF', '#4B0082',
                    '#9400D3', '#FF1493', '#00CED1', '#FFD700', '#FF4500', '#8B4513',
                    '#2F4F4F', '#000000', '#FFFFFF', '#808080', '#FF69B4', '#7FFF00'
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

                init() {
                    this.fetchLogoPrice();
                },

                selectModel(model) {
                    this.selectedModel = model;
                    this.fetchLogoPrice();
                },

                toggleColor(color) {
                    const index = this.selectedColors.indexOf(color);
                    if (index > -1) {
                        this.selectedColors.splice(index, 1);
                    } else {
                        this.selectedColors.push(color);
                    }
                    this.fetchLogoPrice();
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
                            colors: this.selectedColors,
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
