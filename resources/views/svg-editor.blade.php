<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>SVG Editor - Logo Generator</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        [x-cloak] { display: none !important; }
        .draggable-text {
            cursor: move;
            user-select: none;
        }
        .draggable-text:hover {
            opacity: 0.8;
        }
    </style>
</head>
<body class="bg-gray-100 dark:bg-gray-900">
    <div x-data="svgEditor()" x-init="init()" class="min-h-screen">
        <!-- Header -->
        <div class="bg-gradient-to-r from-amber-500 to-orange-500 px-6 py-4 shadow-lg">
            <div class="max-w-7xl mx-auto flex items-center justify-between">
                <h1 class="text-2xl font-bold text-white">SVG Editor</h1>
                <a href="/logo-generator-2" class="px-4 py-2 bg-white/20 hover:bg-white/30 text-white rounded-lg transition-colors">
                    ← Back to Generator
                </a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="max-w-7xl mx-auto p-6">
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                <!-- Left Sidebar: Tools -->
                <div class="lg:col-span-1 space-y-6">
                    <!-- Color Editor -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 space-y-4">
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Colors</h2>
                        <template x-for="(colorInfo, index) in colors" :key="index">
                            <div class="space-y-2">
                                <div class="flex items-center gap-3">
                                    <input 
                                        type="color" 
                                        :value="colorInfo.current"
                                        @input="updateColor(colorInfo.original, $event.target.value)"
                                        class="w-12 h-10 rounded border border-gray-300 dark:border-gray-600 cursor-pointer"
                                    >
                                    <input 
                                        type="text" 
                                        :value="colorInfo.current"
                                        @input="updateColor(colorInfo.original, $event.target.value)"
                                        class="flex-1 px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white font-mono"
                                    >
                                </div>
                            </div>
                        </template>
                        <p x-show="colors.length === 0" class="text-sm text-gray-500 dark:text-gray-400">
                            No colors detected in SVG
                        </p>
                    </div>

                    <!-- Text Editor -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 space-y-4">
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Add Text</h2>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Text</label>
                            <input 
                                type="text" 
                                x-model="newText"
                                placeholder="Enter text..."
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
                            >
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Font Family</label>
                            <select 
                                x-model="fontFamily"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
                            >
                                <option value="Arial">Arial</option>
                                <option value="Helvetica">Helvetica</option>
                                <option value="Times New Roman">Times New Roman</option>
                                <option value="Georgia">Georgia</option>
                                <option value="Verdana">Verdana</option>
                                <option value="Impact">Impact</option>
                                <option value="Trebuchet MS">Trebuchet MS</option>
                                <option value="Courier New">Courier New</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Font Size</label>
                            <input 
                                type="number" 
                                x-model="fontSize"
                                min="12"
                                max="200"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
                            >
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Text Color</label>
                            <div class="flex items-center gap-3">
                                <input 
                                    type="color" 
                                    x-model="textColor"
                                    class="w-12 h-10 rounded border border-gray-300 dark:border-gray-600 cursor-pointer"
                                >
                                <input 
                                    type="text" 
                                    x-model="textColor"
                                    class="flex-1 px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white font-mono"
                                >
                            </div>
                        </div>

                        <button 
                            @click="addText()"
                            class="w-full px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white font-medium rounded-lg transition-colors"
                        >
                            Add Text
                        </button>
                        
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            💡 Tip: Click and drag text to reposition
                        </p>
                    </div>

                    <!-- Actions -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 space-y-3">
                        <button 
                            @click="downloadSvg()"
                            class="w-full px-4 py-3 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-lg transition-colors"
                        >
                            Download SVG
                        </button>
                        <button 
                            @click="resetSvg()"
                            class="w-full px-4 py-3 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-200 font-medium rounded-lg transition-colors"
                        >
                            Reset to Original
                        </button>
                    </div>
                </div>

                <!-- Right: Canvas -->
                <div class="lg:col-span-3">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-8">
                        <div class="flex items-center justify-center bg-gray-50 dark:bg-gray-900 rounded-lg p-8 min-h-[600px]">
                            <div id="svg-canvas" class="max-w-full"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script>
        function svgEditor() {
            return {
                svgUrl: '',
                originalSvgContent: '',
                svgElement: null,
                colors: [],
                colorMap: new Map(),
                
                // Text properties
                newText: '',
                fontSize: 48,
                fontFamily: 'Arial',
                textColor: '#000000',
                
                // Drag state
                draggedElement: null,
                dragOffset: { x: 0, y: 0 },

                init() {
                    // Get SVG URL from query parameter
                    const params = new URLSearchParams(window.location.search);
                    this.svgUrl = params.get('url');
                    
                    if (!this.svgUrl) {
                        alert('No SVG URL provided');
                        return;
                    }
                    
                    this.loadSvg();
                },

                async loadSvg() {
                    try {
                        const response = await fetch(this.svgUrl);
                        const svgText = await response.text();
                        this.originalSvgContent = svgText;
                        
                        // Parse and display SVG
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(svgText, 'image/svg+xml');
                        this.svgElement = doc.querySelector('svg');
                        
                        if (this.svgElement) {
                            // Make SVG responsive
                            if (!this.svgElement.getAttribute('viewBox')) {
                                const width = this.svgElement.getAttribute('width') || 512;
                                const height = this.svgElement.getAttribute('height') || 512;
                                this.svgElement.setAttribute('viewBox', `0 0 ${width} ${height}`);
                            }
                            this.svgElement.setAttribute('width', '100%');
                            this.svgElement.setAttribute('height', 'auto');
                            this.svgElement.style.maxWidth = '600px';
                            
                            // Insert into canvas
                            const canvas = document.getElementById('svg-canvas');
                            canvas.innerHTML = '';
                            canvas.appendChild(this.svgElement);
                            
                            // Extract colors
                            this.extractColors();
                        }
                    } catch (error) {
                        console.error('Failed to load SVG:', error);
                        alert('Failed to load SVG');
                    }
                },

                extractColors() {
                    if (!this.svgElement) return;
                    
                    const colorSet = new Set();
                    const elements = this.svgElement.querySelectorAll('*');
                    
                    elements.forEach(el => {
                        ['fill', 'stroke'].forEach(attr => {
                            const value = el.getAttribute(attr);
                            if (value && value !== 'none' && value.startsWith('#')) {
                                colorSet.add(value.toUpperCase());
                            }
                        });
                        
                        // Check inline styles
                        const style = el.getAttribute('style');
                        if (style) {
                            const fillMatch = style.match(/fill:\s*(#[0-9A-Fa-f]{6})/);
                            const strokeMatch = style.match(/stroke:\s*(#[0-9A-Fa-f]{6})/);
                            if (fillMatch) colorSet.add(fillMatch[1].toUpperCase());
                            if (strokeMatch) colorSet.add(strokeMatch[1].toUpperCase());
                        }
                    });
                    
                    this.colors = Array.from(colorSet).map(color => ({
                        original: color,
                        current: color
                    }));
                },

                updateColor(originalColor, newColor) {
                    if (!this.svgElement) return;
                    
                    // Normalize colors
                    const oldColor = originalColor.toUpperCase();
                    const newColorNormalized = newColor.toUpperCase();
                    
                    // Update all elements with this color
                    const elements = this.svgElement.querySelectorAll('*');
                    elements.forEach(el => {
                        ['fill', 'stroke'].forEach(attr => {
                            const value = el.getAttribute(attr);
                            if (value && value.toUpperCase() === oldColor) {
                                el.setAttribute(attr, newColorNormalized);
                            }
                        });
                        
                        // Update inline styles
                        const style = el.getAttribute('style');
                        if (style) {
                            let newStyle = style.replace(
                                new RegExp(`fill:\\s*${oldColor}`, 'gi'),
                                `fill: ${newColorNormalized}`
                            );
                            newStyle = newStyle.replace(
                                new RegExp(`stroke:\\s*${oldColor}`, 'gi'),
                                `stroke: ${newColorNormalized}`
                            );
                            if (newStyle !== style) {
                                el.setAttribute('style', newStyle);
                            }
                        }
                    });
                    
                    // Update the color in our array
                    const colorInfo = this.colors.find(c => c.original === originalColor);
                    if (colorInfo) {
                        colorInfo.current = newColorNormalized;
                        colorInfo.original = newColorNormalized; // Update original so future changes work
                    }
                },

                addText() {
                    if (!this.newText || !this.svgElement) return;
                    
                    // Get SVG dimensions
                    const viewBox = this.svgElement.getAttribute('viewBox')?.split(' ') || [0, 0, 512, 512];
                    const width = parseFloat(viewBox[2]);
                    const height = parseFloat(viewBox[3]);
                    
                    // Create text element
                    const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                    text.setAttribute('x', width / 2);
                    text.setAttribute('y', height - 50);
                    text.setAttribute('text-anchor', 'middle');
                    text.setAttribute('font-family', this.fontFamily);
                    text.setAttribute('font-size', this.fontSize);
                    text.setAttribute('fill', this.textColor);
                    text.setAttribute('font-weight', 'bold');
                    text.classList.add('draggable-text');
                    text.textContent = this.newText;
                    
                    // Add drag functionality
                    this.makeDraggable(text);
                    
                    // Add to SVG
                    this.svgElement.appendChild(text);
                    
                    // Clear input
                    this.newText = '';
                },

                makeDraggable(element) {
                    let isDragging = false;
                    let startX, startY, initialX, initialY;
                    
                    const getCoords = (e) => {
                        const svg = this.svgElement;
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

                downloadSvg() {
                    if (!this.svgElement) return;
                    
                    const serializer = new XMLSerializer();
                    const svgString = serializer.serializeToString(this.svgElement);
                    
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

                resetSvg() {
                    if (confirm('Reset to original SVG? All changes will be lost.')) {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(this.originalSvgContent, 'image/svg+xml');
                        this.svgElement = doc.querySelector('svg');
                        
                        if (this.svgElement) {
                            if (!this.svgElement.getAttribute('viewBox')) {
                                const width = this.svgElement.getAttribute('width') || 512;
                                const height = this.svgElement.getAttribute('height') || 512;
                                this.svgElement.setAttribute('viewBox', `0 0 ${width} ${height}`);
                            }
                            this.svgElement.setAttribute('width', '100%');
                            this.svgElement.setAttribute('height', 'auto');
                            this.svgElement.style.maxWidth = '600px';
                            
                            const canvas = document.getElementById('svg-canvas');
                            canvas.innerHTML = '';
                            canvas.appendChild(this.svgElement);
                            
                            this.extractColors();
                        }
                    }
                }
            };
        }
    </script>
</body>
</html>
