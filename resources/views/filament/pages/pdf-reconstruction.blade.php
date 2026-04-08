<x-filament-panels::page>
    <div x-data="pdfRecon()" x-init="init()">

        {{-- ═══════════════════════════════════════════════════════════
             HEADER BAR
        ═══════════════════════════════════════════════════════════ --}}
        <div class="flex items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-xl font-bold text-gray-900 dark:text-white">PDF Reconstruction</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    Load a document by ID, then draw individual annotations at their absolute positions.
                </p>
            </div>
            <a href="{{ route('filament.admin.pages.run-pdf-tests') }}">
                <x-filament::button color="gray" size="sm" icon="heroicon-o-arrow-left">PDF Tests</x-filament::button>
            </a>
        </div>

        {{-- ═══════════════════════════════════════════════════════════
             DOCUMENT LOAD BAR
        ═══════════════════════════════════════════════════════════ --}}
        <div class="bg-white dark:bg-gray-800 rounded-2xl ring-1 ring-gray-950/5 dark:ring-white/10 p-5 mb-6">
            <div class="flex items-end gap-3">
                <div class="flex-1 max-w-xs">
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">Document ID</label>
                    <input
                        type="number"
                        min="1"
                        x-model="docIdInput"
                        x-on:keydown.enter="loadDocument()"
                        placeholder="e.g. 42"
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500"
                    >
                </div>
                <x-filament::button
                    x-on:click="loadDocument()"
                    x-bind:disabled="loading || !docIdInput"
                    icon="heroicon-o-arrow-down-tray"
                    size="sm"
                >
                    <span x-show="!loading">Load</span>
                    <span x-show="loading" x-cloak>Loading…</span>
                </x-filament::button>
                <template x-if="document">
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        <span class="font-medium text-gray-900 dark:text-white" x-text="document.name"></span>
                        <span x-text="' · ' + annotations.length + ' annotation' + (annotations.length === 1 ? '' : 's')"></span>
                    </div>
                </template>
            </div>
            <template x-if="error">
                <p class="mt-3 text-sm text-danger-600 dark:text-danger-400" x-text="error"></p>
            </template>
        </div>

        {{-- ═══════════════════════════════════════════════════════════
             MAIN WORKSPACE (shown only when a document is loaded)
        ═══════════════════════════════════════════════════════════ --}}
        <template x-if="document && pdfLoaded">
            <div class="space-y-4">

            {{-- PDF Source Selector + panel toggle --}}
            <div class="flex items-center justify-between gap-4 flex-wrap">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 mr-1">PDF source:</span>
                    <template x-for="src in pdfSources" :key="src.key">
                        <button type="button"
                            x-on:click="switchSource(src.key)"
                            x-bind:class="activeSrc === src.key
                                ? 'bg-primary-600 text-white shadow-sm'
                                : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 ring-1 ring-gray-950/10 dark:ring-white/10 hover:bg-gray-50 dark:hover:bg-gray-700'"
                            class="text-xs font-medium px-3 py-1.5 rounded-lg transition-colors">
                            <span x-text="src.label"></span>
                            <template x-if="activeSrc === src.key && sourceLoading">
                                <span class="ml-1 opacity-60">…</span>
                            </template>
                        </button>
                    </template>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    {{-- Open New: full-document preview via Python writer --}}
                    <a x-bind:href="'{{ route('filament.admin.pages.pdf-preview') }}?doc=' + document.id"
                       target="_blank"
                       class="flex items-center gap-1.5 text-xs font-medium text-primary-600 dark:text-primary-400 hover:text-primary-800 dark:hover:text-primary-300 bg-primary-50 dark:bg-primary-900/30 ring-1 ring-primary-200 dark:ring-primary-700 hover:bg-primary-100 dark:hover:bg-primary-900/50 px-3 py-1.5 rounded-lg transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                        Open New
                    </a>
                    <button type="button"
                    x-on:click="panelOpen = !panelOpen"
                    class="flex items-center gap-1.5 text-xs font-medium text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white bg-white dark:bg-gray-800 ring-1 ring-gray-950/10 dark:ring-white/10 hover:bg-gray-50 dark:hover:bg-gray-700 px-3 py-1.5 rounded-lg transition-colors">
                    <svg class="w-3.5 h-3.5 transition-transform duration-200" x-bind:class="panelOpen ? '' : 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 5l7 7-7 7M5 5l7 7-7 7"/>
                    </svg>
                    <span x-text="panelOpen ? 'Hide annotations' : 'Show annotations'"></span>
                    </button>
                </div>
            </div>

            <div class="flex gap-5 items-start">

                {{-- ── PDF VIEWER ── --}}
                <div class="flex-1 min-w-0">
                    <div class="bg-white dark:bg-gray-800 rounded-2xl ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">

                        {{-- Page Navigation --}}
                        <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-gray-100 dark:border-gray-700 flex-wrap">
                            <div class="flex items-center gap-2">
                                <button type="button"
                                    x-on:click="prevPage()"
                                    x-bind:disabled="currentPage <= 1"
                                    class="p-1.5 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                                    </svg>
                                </button>
                                <span class="text-sm text-gray-700 dark:text-gray-300">
                                    Page <span class="font-semibold" x-text="currentPage"></span>
                                    of <span x-text="pageCount"></span>
                                </span>
                                <button type="button"
                                    x-on:click="nextPage()"
                                    x-bind:disabled="currentPage >= pageCount"
                                    class="p-1.5 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                    </svg>
                                </button>
                            </div>

                            {{-- Zoom slider --}}
                            <div class="flex items-center gap-2">
                                <button type="button"
                                    x-on:click="setZoom(Math.max(0.5, zoomLevel - 0.25))"
                                    class="p-1 rounded text-gray-400 dark:text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                                    title="Zoom out">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/>
                                    </svg>
                                </button>
                                <input type="range"
                                    min="0.5" max="4" step="0.25"
                                    x-bind:value="zoomLevel"
                                    x-on:input="setZoom($event.target.value)"
                                    class="w-28 h-1.5 rounded-full accent-primary-600 cursor-pointer"
                                    title="Zoom">
                                <button type="button"
                                    x-on:click="setZoom(Math.min(4, zoomLevel + 0.25))"
                                    class="p-1 rounded text-gray-400 dark:text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                                    title="Zoom in">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                                    </svg>
                                </button>
                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 w-9 text-right tabular-nums" x-text="zoomPercent + '%'"></span>
                                <button type="button"
                                    x-show="zoomLevel !== 1.5"
                                    x-cloak
                                    x-on:click="setZoom(1.5)"
                                    class="text-xs text-gray-400 dark:text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition-colors"
                                    title="Reset zoom">↺</button>
                            </div>

                            <div class="flex items-center gap-2">
                                <span class="text-xs text-gray-400 dark:text-gray-500" x-text="drawnCount + ' drawn on this page'"></span>
                                <button type="button"
                                    x-show="drawnCount > 0"
                                    x-cloak
                                    x-on:click="clearPageDrawings()"
                                    class="text-xs text-danger-500 hover:text-danger-700 dark:text-danger-400 dark:hover:text-danger-300 transition-colors">
                                    Clear
                                </button>
                            </div>

                            {{-- Pan toggle --}}
                            <button type="button"
                                x-on:click="panMode = !panMode"
                                x-bind:class="panMode
                                    ? 'bg-primary-100 dark:bg-primary-900/40 text-primary-600 dark:text-primary-400 ring-1 ring-primary-400'
                                    : 'text-gray-400 dark:text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'"
                                class="p-1 rounded transition-colors"
                                title="Pan mode — drag to scroll">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 11.5V14m0-2.5v-6a1.5 1.5 0 013 0m-3 6a1.5 1.5 0 00-3 0v2a7.5 7.5 0 0015 0v-5a1.5 1.5 0 00-3 0m-6-3V11m0-5.5v-1a1.5 1.5 0 013 0v1m0 0V11m0-5.5a1.5 1.5 0 013 0v3m0 0V11"/>
                                </svg>
                            </button>
                        </div>

                        {{-- Status strip --}}
                        <template x-if="drawnCount > 0 || splitView">
                            <div class="px-4 py-1.5 bg-success-50 dark:bg-success-900/20 border-b border-success-100 dark:border-success-800 text-xs text-success-600 dark:text-success-400 flex items-center gap-2">
                                <svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                <span x-text="drawnCount"></span> annotation(s) as text overlay
                                <template x-if="splitView">
                                    <span class="ml-2 text-primary-600 dark:text-primary-400 font-medium">· Comparing vs original</span>
                                </template>
                                <template x-if="splitView">
                                    <button type="button"
                                            x-on:click="splitView = false"
                                            class="ml-auto text-xs font-medium text-danger-500 hover:text-danger-700 dark:text-danger-400 dark:hover:text-danger-300 transition-colors">
                                        Close comparison
                                    </button>
                                </template>
                            </div>
                        </template>
                        <template x-if="splitLoading">
                            <div class="px-4 py-1.5 bg-primary-50 dark:bg-primary-900/20 border-b border-primary-100 dark:border-primary-800 text-xs text-primary-600 dark:text-primary-400 flex items-center gap-2">
                                <svg class="w-3 h-3 animate-spin shrink-0" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4l3-3-3-3v4a8 8 0 00-8 8h4z"/></svg>
                                Loading original PDF…
                            </div>
                        </template>
                        <template x-if="splitError">
                            <div class="px-4 py-1.5 bg-danger-50 dark:bg-danger-900/20 border-b border-danger-100 dark:border-danger-800 text-xs text-danger-600 dark:text-danger-400" x-text="splitError"></div>
                        </template>

                        <div x-ref="pdfScroll"
                             class="relative overflow-auto bg-gray-100 dark:bg-gray-950 flex justify-center items-start flex-wrap py-4 px-4 gap-6"
                             x-bind:class="panMode ? (_panDragging ? 'cursor-grabbing' : 'cursor-grab') : ''"
                             x-on:pointerdown="panStart($event)"
                             x-on:pointermove="panMove($event)"
                             x-on:pointerup="panEnd()"
                             x-on:pointercancel="panEnd()">
                            {{-- LEFT: clean PDF + annotation text overlay --}}
                            <div class="flex-shrink-0">
                                <div x-show="splitView" class="text-center text-xs font-medium text-gray-500 dark:text-gray-400 mb-2">Clean + annotation overlay</div>
                                <div class="relative inline-block" x-bind:style="'width:' + canvasWidth + 'px; height:' + canvasHeight + 'px;'">
                                    <canvas x-ref="pdfCanvas"
                                            class="block shadow-lg"
                                            x-bind:width="canvasWidth"
                                            x-bind:height="canvasHeight">
                                    </canvas>
                                    <div x-ref="annOverlay"
                                         style="position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;overflow:visible;">
                                    </div>
                                </div>
                            </div>
                            {{-- RIGHT: original annotated PDF (shown when comparing) --}}
                            <div class="flex-shrink-0" x-show="splitView" x-cloak>
                                <div class="text-center text-xs font-medium text-gray-500 dark:text-gray-400 mb-2">Original PDF</div>
                                <canvas x-ref="origCanvas"
                                        class="block shadow-lg"
                                        x-bind:width="splitCanvasWidth"
                                        x-bind:height="splitCanvasHeight">
                                </canvas>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ── RIGHT: ANNOTATION LIST ── --}}
                <div x-show="panelOpen"
                     x-cloak
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 translate-x-4"
                     x-transition:enter-end="opacity-100 translate-x-0"
                     x-transition:leave="transition ease-in duration-100"
                     x-transition:leave-start="opacity-100 translate-x-0"
                     x-transition:leave-end="opacity-0 translate-x-4"
                     class="w-80 shrink-0 sticky top-4 self-start"
                     style="max-height: calc(100vh - 2rem);">
                    <div class="bg-white dark:bg-gray-800 rounded-2xl ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden flex flex-col" style="max-height: calc(100vh - 2rem);">

                        {{-- List header --}}
                        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between gap-2">
                            <span class="text-sm font-semibold text-gray-900 dark:text-white">Annotations</span>
                            <div class="flex items-center gap-2">
                                <label class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400 cursor-pointer select-none">
                                    <input type="checkbox" x-model="filterCurrentPage" class="rounded text-primary-600">
                                    <span>This page only</span>
                                </label>
                                <span class="text-xs text-gray-400 dark:text-gray-500"
                                      x-text="filteredAnnotations.length + '/' + annotations.length">
                                </span>
                            </div>
                        </div>

                        {{-- Draw all on page --}}
                        <template x-if="pageAnnotations.length > 0">
                            <div class="px-4 py-2 border-b border-gray-100 dark:border-gray-700 flex items-center gap-2">
                                <button type="button"
                                    x-on:click="drawAllOnPage()"
                                    class="text-xs font-medium text-primary-600 dark:text-primary-400 hover:text-primary-800 dark:hover:text-primary-300 transition-colors">
                                    Draw all on page <span x-text="currentPage"></span>
                                </button>
                                <span class="text-gray-300 dark:text-gray-600">·</span>
                                <button type="button"
                                    x-on:click="clearAll()"
                                    class="text-xs font-medium text-danger-500 dark:text-danger-400 hover:text-danger-700 dark:hover:text-danger-300 transition-colors">
                                    Clear all
                                </button>
                            </div>
                        </template>

                        {{-- Annotation rows --}}
                        <div class="overflow-y-auto flex-1 min-h-0" style="min-height: 0; max-height: calc(100vh - 10rem);">
                            <template x-if="filteredAnnotations.length === 0">
                                <div class="px-4 py-8 text-center text-sm text-gray-400 dark:text-gray-500">
                                    No annotations found.
                                </div>
                            </template>
                            <template x-for="ann in filteredAnnotations" :key="ann._uid">
                                <div class="px-4 py-3 border-b border-gray-50 dark:border-gray-700/50 last:border-b-0 hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
                                    <div class="flex items-start justify-between gap-2 mb-1.5">
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <span class="inline-flex px-1.5 py-0.5 rounded text-xs font-semibold"
                                                  x-bind:class="typeColorClass(ann.type)"
                                                  x-text="ann.type || 'text'">
                                            </span>
                                            <span class="inline-flex px-1.5 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400"
                                                  x-text="'p.' + ((Number(ann.pageIndex) || 0) + 1)">
                                            </span>
                                            <span x-show="ann.db_state === 'saved'"
                                                  class="inline-flex px-1.5 py-0.5 rounded text-xs font-medium bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400">
                                                saved
                                            </span>
                                        </div>
                                        <button type="button"
                                            x-on:click="toggleDraw(ann)"
                                            x-bind:disabled="renderLoading"
                                            x-bind:class="isDrawn(ann)
                                                ? 'bg-primary-600 text-white hover:bg-primary-700'
                                                : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'"
                                            class="shrink-0 text-xs font-semibold px-2.5 py-1 rounded-lg transition-colors whitespace-nowrap flex items-center gap-1">
                                            <template x-if="renderLoading && isDrawn(ann)">
                                                <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4l3-3-3-3v4a8 8 0 00-8 8h4z"/></svg>
                                            </template>
                                            <span x-text="isDrawn(ann) ? 'Drawn ✓' : 'Draw'"></span>
                                        </button>
                                    </div>
                                    <p class="text-xs text-gray-600 dark:text-gray-400 leading-snug break-words line-clamp-2"
                                       x-text="annotationPreview(ann)">
                                    </p>
                                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500 font-mono"
                                       x-text="positionSummary(ann)">
                                    </p>
                                    <div class="mt-2">
                                        <button type="button"
                                            x-on:click="compareToOriginal(ann)"
                                            x-bind:class="splitView && splitAnn && splitAnn._uid === ann._uid
                                                ? 'text-primary-700 dark:text-primary-300 font-semibold'
                                                : 'text-primary-600 dark:text-primary-400 hover:text-primary-800 dark:hover:text-primary-300'"
                                            class="inline-flex items-center gap-1 text-xs font-medium transition-colors">
                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 0v10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"/></svg>
                                            Compare vs original
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

            </div>
            </div>{{-- /space-y-4 --}}
        </template>
        {{-- Loading skeleton while PDF renders --}}
        <template x-if="document && !pdfLoaded">
            <div class="flex items-center justify-center py-16">
                <svg class="animate-spin h-6 w-6 text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="ml-3 text-sm text-gray-500 dark:text-gray-400">Rendering PDF…</span>
            </div>
        </template>

    </div>{{-- /x-data pdfRecon --}}

    <style>
        [x-cloak] { display: none !important; }

        .recon-ann {
            position: absolute;
            box-sizing: border-box;
        }
    </style>

    {{-- pdfjs CDN — load both main lib and worker script (matching edit.blade.php pattern) --}}
    <script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js"></script>
    <script>
        if (typeof pdfjsLib !== 'undefined') {
            pdfjsLib.GlobalWorkerOptions.workerSrc =
                'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';
        }
    </script>

    <script>
        function pdfRecon() {
            // ── pdfjs objects stored OUTSIDE Alpine reactive state ──
            // Storing PDFDocumentProxy / PDFPageProxy inside Alpine's reactive
            // proxy causes "Cannot read private member #d" errors because Alpine
            // wraps them in a JS Proxy, breaking pdfjs's internal private field
            // ("#d") checks on class instances.
            let _pdfDoc      = null;
            let _renderTask  = null;
            let _origPdfDoc  = null;
            let _origRenderTask = null;
            let _exactTextWidthProbe = null;

            const _FONT_MAP = {
                Helvetica:     '"AnnotHelvetica","Arimo",Arial,sans-serif',
                Arial:         '"AnnotHelvetica","Arimo",Arial,sans-serif',
                Verdana:       'Verdana,Geneva,Arial,sans-serif',
                Tahoma:        '"Arimo",Tahoma,Arial,Verdana,sans-serif',
                TahomaUnicode: '"Arimo",Tahoma,Arial,Verdana,sans-serif',
                TimesRoman:    '"TimesRoman","Tinos","Times New Roman",Times,serif',
                TimesNewRoman: '"TimesRoman","Tinos","Times New Roman",Times,serif',
                Times:         '"TimesRoman","Tinos","Times New Roman",Times,serif',
                Palatino:      '"AnnotPalatino","Liberation Serif","Times New Roman",serif',
                BookAntiqua:   '"AnnotPalatino","Liberation Serif","Times New Roman",serif',
                Courier:       '"Courier","Cousine","Courier New",monospace',
                Garamond:      '"AnnotGaramond","EB Garamond",Baskerville,serif',
                Georgia:       '"Georgia","Liberation Serif",serif',
                Calibri:       'Calibri,Arial,sans-serif',
                Roboto:        '"Roboto",Arial,Helvetica,sans-serif',
                Lato:          '"Lato",Arial,Helvetica,sans-serif',
                Montserrat:    '"Montserrat",Arial,Helvetica,sans-serif',
            };
            // ── Embedded font registry (populated per-document from the API) ──
            let _embeddedFonts = null;  // { cleanName: { family, css_weight, css_style, css_stretch, file_path, file_ext } }

            // Inject @font-face rules for all embedded fonts in the document.
            // Each font is registered under 'PDF_{clean_name}' so it can be used
            // in CSS without conflicting with system fonts.
            function _loadEmbeddedFontFaces(embeddedFonts) {
                _embeddedFonts = null;
                const existing = document.getElementById('recon-embedded-fonts');
                if (existing) existing.remove();
                if (!embeddedFonts || typeof embeddedFonts !== 'object') return;
                _embeddedFonts = embeddedFonts;
                let css = '';
                for (const [fontKey, fontData] of Object.entries(embeddedFonts)) {
                    const cleanName = String(fontData.clean_name || fontKey || '').trim();
                    if (!cleanName) continue;
                    let filePath = String(fontData.file_path || '').trim();
                    if (!filePath) continue;
                    let ext = String(fontData.file_ext || 'otf').toLowerCase();
                    // Prefer OTF over raw CFF (browsers can't load raw CFF)
                    if (ext === 'cff') { filePath = filePath.replace(/\.cff$/i, '.otf'); ext = 'otf'; }
                    if (ext === 'cid') continue; // cannot load in browser
                    const weight  = fontData.css_weight  || '400';
                    const style   = fontData.css_style   || 'normal';
                    const stretch = fontData.css_stretch && fontData.css_stretch !== 'normal'
                        ? fontData.css_stretch : null;
                    const fmt = (ext === 'woff2') ? 'woff2'
                              : (ext === 'woff')  ? 'woff'
                              : 'opentype';
                    css += `@font-face { font-family: 'PDF_${cleanName}'; src: url('${filePath}') format('${fmt}'); font-weight: ${weight}; font-style: ${style};${stretch ? ` font-stretch: ${stretch};` : ''} font-display: block; }\n`;
                }
                if (!css) return;
                const style = document.createElement('style');
                style.id = 'recon-embedded-fonts';
                style.textContent = css;
                document.head.appendChild(style);
            }

            function _resolveCssFont(sourceName, family) {
                // Normalize PostScript suffixes: TimesNewRomanPSMT → TimesNewRoman.
                // PDF fonts with PSMT / PS-<Variant>MT suffixes are identical to
                // the base font and must resolve to the same embedded font entry.
                const _normPsName = (n) => String(n || '').trim()
                    .replace(/PSMT$/i, '')
                    .replace(/PS(-\w+MT)$/i, '$1')
                    .trim();
                // 1. Try embedded font by exact source name (fontSourceName)
                const rawExact = _normPsName(sourceName || family || '');
                if (rawExact && _embeddedFonts) {
                    for (const [fontKey, fontData] of Object.entries(_embeddedFonts)) {
                        const cleanName = String(fontData.clean_name || fontKey || '').trim();
                        if (cleanName.toLowerCase() === rawExact.toLowerCase()) {
                            const fallback = _fontMapFallback(String(fontData.family || rawExact));
                            return `'PDF_${cleanName}', ${fallback}`;
                        }
                    }
                    // Try family-level match
                    const rawFamily = _normPsName(family || '');
                    if (rawFamily) {
                        for (const [fontKey, fontData] of Object.entries(_embeddedFonts)) {
                            const embFamily = String(fontData.family || fontKey || '').trim();
                            if (embFamily.toLowerCase() === rawFamily.toLowerCase()) {
                                const cleanName = String(fontData.clean_name || fontKey || '').trim();
                                const fallback = _fontMapFallback(embFamily);
                                return `'PDF_${cleanName}', ${fallback}`;
                            }
                        }
                    }
                }
                // 2. Fall back to static font map
                return _fontMapFallback(rawExact || '');
            }

            function _fontMapFallback(name) {
                if (!name) return _FONT_MAP.Helvetica;
                const k = String(name).replace(/['"]/g, '').trim()
                    .replace(/[-_ ]?(regular|bold|italic|oblique|light|medium|condensed|narrow|unicode)$/i, '');
                for (const [key, val] of Object.entries(_FONT_MAP)) {
                    if (key.toLowerCase() === k.toLowerCase()) return val;
                }
                return _FONT_MAP.Helvetica;
            }

            function _ensureExactTextWidthProbe() {
                if (_exactTextWidthProbe instanceof HTMLElement && _exactTextWidthProbe.isConnected) {
                    return _exactTextWidthProbe;
                }
                const probe = document.createElement('span');
                probe.setAttribute('aria-hidden', 'true');
                probe.style.position = 'fixed';
                probe.style.left = '-100000px';
                probe.style.top = '-100000px';
                probe.style.visibility = 'hidden';
                probe.style.pointerEvents = 'none';
                probe.style.whiteSpace = 'pre';
                probe.style.padding = '0';
                probe.style.margin = '0';
                probe.style.border = '0';
                probe.style.transform = 'none';
                probe.style.transformOrigin = 'left top';
                document.body.appendChild(probe);
                _exactTextWidthProbe = probe;
                return probe;
            }

            function _measureExactTextDomWidth(text, fontSizePx, fontFamily, fontWeight, fontStyle, fontStretch = '') {
                const probe = _ensureExactTextWidthProbe();
                if (!(probe instanceof HTMLElement)) return 0;
                probe.textContent = String(text || '');
                probe.style.fontFamily = fontFamily || '';
                probe.style.fontSize = `${Math.max(0, Number(fontSizePx) || 0)}px`;
                probe.style.fontWeight = fontWeight || '400';
                probe.style.fontStyle = fontStyle || 'normal';
                probe.style.fontStretch = String(fontStretch || '').trim() || 'normal';
                const rect = probe.getBoundingClientRect();
                return rect.width || 0;
            }

            function _applyExactTextWidthFit(element, {
                text = '',
                targetWidthPx = 0,
                fontSizePx = 0,
                fontFamily = '',
                fontWeight = '400',
                fontStyle = 'normal',
                fontStretch = '',
                minRatio = 0.5,
                maxRatio = 1.5,
            } = {}) {
                if (!(element instanceof HTMLElement)) return false;

                const targetWidth = Number(targetWidthPx) || 0;
                const effectiveFontSize = Number(fontSizePx) || 0;
                const sampleText = String(text ?? '');
                if (targetWidth <= 0 || effectiveFontSize <= 0 || !sampleText) {
                    element.style.transform = '';
                    element.style.transformOrigin = '';
                    return false;
                }

                const measuredWidth = _measureExactTextDomWidth(
                    sampleText,
                    effectiveFontSize,
                    fontFamily,
                    fontWeight,
                    fontStyle,
                    fontStretch
                );
                if (!Number.isFinite(measuredWidth) || measuredWidth <= 0) {
                    element.style.transform = '';
                    element.style.transformOrigin = '';
                    return false;
                }

                const rawRatio = targetWidth / measuredWidth;
                if (!Number.isFinite(rawRatio) || rawRatio <= 0) {
                    element.style.transform = '';
                    element.style.transformOrigin = '';
                    return false;
                }

                // Reconstruction should only correct overflow. Expanding text to fill
                // a larger extracted bbox stretches lines that were already visually right.
                if (rawRatio >= 0.985) {
                    element.style.transform = '';
                    element.style.transformOrigin = '';
                    return false;
                }

                const clampedRatio = Math.max(minRatio, Math.min(maxRatio, rawRatio));
                if (Math.abs(clampedRatio - 1) <= 0.015) {
                    element.style.transform = '';
                    element.style.transformOrigin = '';
                    return false;
                }

                element.style.transformOrigin = 'left top';
                element.style.transform = `scaleX(${clampedRatio})`;
                return true;
            }



            return {
                /* ── input ── */
                docIdInput: '',

                /* ── state ── */
                loading:       false,
                sourceLoading: false,
                error:         null,
                document:      null,   // {id, name, file_url, original_url, clean_url}
                pdfLoaded:     false,

                /* ── panel ── */
                panelOpen: true,

                /* ── pdf source ── */
                activeSrc:  'clean',   // 'clean' | 'file'
                pdfSources: [
                    { key: 'clean', label: 'Clean / redacted' },
                    { key: 'file',  label: 'Current (annotated)' },
                ],

                /* ── page ── */
                pageCount:    0,
                currentPage:  1,
                canvasWidth:  0,
                canvasHeight: 0,
                pageWidthPts:  0,
                pageHeightPts: 0,

                /* ── annotations ── */
                annotations: [],
                drawnIds:    {},   // {[uid]: true}
                renderLoading: false,
                /* ── split-view comparison ── */
                splitView:         false,
                splitLoading:      false,
                splitError:        null,
                splitCanvasWidth:  0,
                splitCanvasHeight: 0,
                splitAnn:          null,   // annotation currently being compared

                /* ── zoom ── */
                zoomLevel: 1.5,

                /* ── pan ── */
                panMode: false,
                _panDragging: false,
                _panStart: {x: 0, y: 0},
                _panScroll: {l: 0, t: 0},

                /* ── filter ── */
                filterCurrentPage: false,

                /* ── inits ── */
                init() {},


                /* ─────────────────────────────────────────────── */
                /* COMPUTED-LIKE GETTERS                           */
                /* ─────────────────────────────────────────────── */

                get pageAnnotations() {
                    return this.annotations.filter(
                        (a) => (Number(a.pageIndex) || 0) === this.currentPage - 1
                    );
                },

                get filteredAnnotations() {
                    if (this.filterCurrentPage) return this.pageAnnotations;
                    return this.annotations;
                },

                get drawnCount() {
                    return this.pageAnnotations.filter((a) => this.isDrawn(a)).length;
                },

                get scale() {
                    return this.pageWidthPts > 0 ? (this.canvasWidth / this.pageWidthPts) : this.zoomLevel;
                },

                get zoomPercent() {
                    return Math.round(this.zoomLevel * 100);
                },

                async setZoom(value) {
                    this.zoomLevel = Number(value);
                    await this.renderCurrentPage();
                },

                panStart(e) {
                    if (!this.panMode) return;
                    this._panDragging = true;
                    this._panStart = {x: e.clientX, y: e.clientY};
                    const el = this.$refs.pdfScroll;
                    this._panScroll = {l: el.scrollLeft, t: el.scrollTop};
                    e.preventDefault();
                    e.currentTarget.setPointerCapture(e.pointerId);
                },

                panMove(e) {
                    if (!this._panDragging) return;
                    const dx = e.clientX - this._panStart.x;
                    const dy = e.clientY - this._panStart.y;
                    const el = this.$refs.pdfScroll;
                    el.scrollLeft = this._panScroll.l - dx;
                    el.scrollTop  = this._panScroll.t  - dy;
                },

                panEnd() {
                    this._panDragging = false;
                },

                /* ─────────────────────────────────────────────── */
                /* LOAD DOCUMENT                                   */
                /* ─────────────────────────────────────────────── */

                async loadDocument() {
                    const id = parseInt(this.docIdInput, 10);
                    if (!id || id < 1) return;

                    this.loading      = true;
                    this.error        = null;
                    this.document     = null;
                    _pdfDoc           = null;
                    this.pdfLoaded    = false;
                    this.activeSrc    = 'clean';
                    this.annotations  = [];
                    this.drawnIds     = {};
                    this.pageCount    = 0;
                    this.currentPage  = 1;

                    try {
                        const infoUrl = '{{ route('pdfTests.documentInfo', ['document' => '__ID__']) }}'
                            .replace('__ID__', encodeURIComponent(id));

                        const resp = await fetch(infoUrl, {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                        });

                        if (resp.status === 404) throw new Error('Document #' + id + ' not found.');
                        if (!resp.ok) {
                            const body = await resp.json().catch(() => ({}));
                            throw new Error(body.message || 'HTTP ' + resp.status);
                        }

                        const data = await resp.json();
                        if (!data.success) throw new Error(data.message || 'Failed to load document info.');

                        this.document    = data.document;
                        this.annotations = (data.annotations || []).map((a, i) => ({
                            ...a,
                            _uid: String(a.id || a.db_id || '') + '_' + i,
                        }));

                        // Inject @font-face CSS for embedded fonts from the PDF,
                        // then wait until the browser has parsed/loaded them before rendering.
                        _loadEmbeddedFontFaces(data.embedded_fonts || null);
                        if (data.embedded_fonts) {
                            try { await document.fonts.ready; } catch (_) {}
                        }

                        await this.loadPdf(this.activePdfUrl());
                    } catch (e) {
                        this.error = e.message || String(e);
                    } finally {
                        this.loading = false;
                    }
                },

                // Returns the URL for the currently selected PDF source
                activePdfUrl() {
                    if (!this.document) return '';
                    if (this.activeSrc === 'clean') return this.document.clean_url;
                    return this.document.file_url;
                },

                async switchSource(key) {
                    if (key === this.activeSrc || !this.document) return;
                    this.activeSrc     = key;
                    this.sourceLoading = true;
                    // Preserve drawnIds across tab switches.
                    // When switching to the annotated file view, clear cached render so
                    // the annotated PDF shows cleanly without the clean-base overlay.
                    if (key === 'file') {
                        this.renderedPage = null;
                    }
                    try {
                        await this.loadPdf(this.activePdfUrl());
                        // Overlay is redrawn by renderCurrentPage → redrawAnnotationsOnOverlay
                    } catch (e) {
                        this.error = 'Failed to load PDF: ' + (e.message || String(e));
                    } finally {
                        this.sourceLoading = false;
                    }
                },

                /* ─────────────────────────────────────────────── */
                /* PDF RENDERING                                   */
                /* ─────────────────────────────────────────────── */

                async loadPdf(url) {
                    if (typeof pdfjsLib === 'undefined') throw new Error('PDF.js not loaded.');
                    // Use closure var — keeps pdfjs objects out of Alpine's Proxy
                    _pdfDoc          = await pdfjsLib.getDocument(url).promise;
                    this.pageCount   = _pdfDoc.numPages;
                    this.currentPage = 1;
                    this.pdfLoaded   = true;
                    await this.$nextTick();
                    await this.renderCurrentPage();
                },

                async renderCurrentPage() {
                    if (!_pdfDoc) return;
                    if (_renderTask) {
                        _renderTask.cancel();
                        _renderTask = null;
                    }

                    // page is a raw pdfjs object — never stored in Alpine state
                    const page     = await _pdfDoc.getPage(this.currentPage);
                    const viewport = page.getViewport({ scale: this.zoomLevel });

                    this.pageWidthPts  = page.view[2];
                    this.pageHeightPts = page.view[3];
                    this.canvasWidth   = Math.round(viewport.width);
                    this.canvasHeight  = Math.round(viewport.height);

                    await this.$nextTick();

                    const canvas = this.$refs.pdfCanvas;
                    if (!canvas) return;
                    canvas.width  = this.canvasWidth;
                    canvas.height = this.canvasHeight;

                    const ctx = canvas.getContext('2d');
                    _renderTask = page.render({ canvasContext: ctx, viewport });
                    await _renderTask.promise.catch(() => {});
                    _renderTask = null;
                    this.redrawAnnotationsOnOverlay();
                    if (this.splitView) await this.renderOriginalPage();
                },

                async renderOriginalPage() {
                    if (!_origPdfDoc) return;
                    if (_origRenderTask) { _origRenderTask.cancel(); _origRenderTask = null; }
                    const canvas = this.$refs.origCanvas;
                    if (!canvas) return;
                    const page     = await _origPdfDoc.getPage(this.currentPage);
                    const viewport = page.getViewport({ scale: this.zoomLevel });
                    this.splitCanvasWidth  = Math.round(viewport.width);
                    this.splitCanvasHeight = Math.round(viewport.height);
                    await this.$nextTick();
                    canvas.width  = this.splitCanvasWidth;
                    canvas.height = this.splitCanvasHeight;
                    _origRenderTask = page.render({ canvasContext: canvas.getContext('2d'), viewport });
                    await _origRenderTask.promise.catch(() => {});
                    _origRenderTask = null;
                },

                async compareToOriginal(ann) {
                    if (!this.document) return;
                    // Navigate to annotation's page
                    const targetPage = (Number(ann.pageIndex) || 0) + 1;
                    if (targetPage !== this.currentPage) await this.goToPage(targetPage);
                    // Ensure this annotation is drawn
                    this.drawnIds = { ...this.drawnIds, [ann._uid]: true };
                    this.redrawAnnotationsOnOverlay();
                    this.splitAnn = ann;
                    // Load the original annotated PDF lazily
                    if (!_origPdfDoc) {
                        this.splitLoading = true;
                        this.splitError   = null;
                        try {
                            _origPdfDoc = await pdfjsLib.getDocument(this.document.file_url).promise;
                        } catch (e) {
                            this.splitError   = 'Failed to load original PDF: ' + (e.message || String(e));
                            this.splitLoading = false;
                            return;
                        }
                        this.splitLoading = false;
                    }
                    this.splitView = true;
                    await this.renderOriginalPage();
                },

                async prevPage() {
                    if (this.currentPage > 1) {
                        this.currentPage--;
                        await this.renderCurrentPage();
                    }
                },

                async nextPage() {
                    if (this.currentPage < this.pageCount) {
                        this.currentPage++;
                        await this.renderCurrentPage();
                    }
                },

                async goToPage(pageNum) {
                    const page = Math.max(1, Math.min(this.pageCount, pageNum));
                    if (page !== this.currentPage) {
                        this.currentPage = page;
                        await this.renderCurrentPage();
                    }
                },

                /* ─────────────────────────────────────────────── */
                /* DRAW / TOGGLE                                   */
                /* ─────────────────────────────────────────────── */

                async toggleDraw(ann) {
                    const uid = ann._uid;
                    if (!uid) return;

                    const targetPage = (Number(ann.pageIndex) || 0) + 1;
                    if (targetPage !== this.currentPage) {
                        await this.goToPage(targetPage);
                    }

                    this.drawnIds = {
                        ...this.drawnIds,
                        [uid]: !this.drawnIds[uid],
                    };
                    this.redrawAnnotationsOnOverlay();
                },

                isDrawn(ann) {
                    return Boolean(this.drawnIds[ann._uid]);
                },

                drawAllOnPage() {
                    const updates = {};
                    this.pageAnnotations.forEach((a) => { updates[a._uid] = true; });
                    this.drawnIds = { ...this.drawnIds, ...updates };
                    this.redrawAnnotationsOnOverlay();
                },

                clearPageDrawings() {
                    const updates = {};
                    this.pageAnnotations.forEach((a) => { updates[a._uid] = false; });
                    this.drawnIds = { ...this.drawnIds, ...updates };
                    this.redrawAnnotationsOnOverlay();
                },

                clearAll() {
                    this.drawnIds = {};
                    this.redrawAnnotationsOnOverlay();
                },

                /* ─────────────────────────────────────────────── */
                /* COORDINATE MAPPING                             */
                /* ─────────────────────────────────────────────── */

                // Resolve bounding box in PDF-point space (bottom-origin y).
                // Supports:
                //   1. pdfX/pdfY/pdfWidth/pdfHeight  — regular & dirty-promoted annotations
                //   2. sourceBlockLeft/Top/Width/Height + sourcePageHeight
                //      — promoted-from-extraction annotations stored with top-origin coords
                resolveAnnBox(ann) {
                    const px = Number(ann.pdfX), py = Number(ann.pdfY);
                    const pw = Number(ann.pdfWidth), ph = Number(ann.pdfHeight);
                    if ([px, py, pw, ph].every(Number.isFinite) && pw > 0 && ph > 0) {
                        return { x: px, y: py, w: pw, h: ph };
                    }
                    // Fallback: top-origin source coords → convert to bottom-origin
                    const sL = Number(ann.sourceBlockLeft),  sT = Number(ann.sourceBlockTop);
                    const sW = Number(ann.sourceBlockWidth), sH = Number(ann.sourceBlockHeight);
                    const spH = Number(ann.sourcePageHeight);
                    if ([sL, sT, sW, sH, spH].every(Number.isFinite) && sW > 0 && sH > 0) {
                        return { x: sL, y: spH - (sT + sH), w: sW, h: sH };
                    }
                    return null;
                },

                // ── Imperative overlay rendering (matching editor coordinate/style approach) ──

                redrawAnnotationsOnOverlay() {
                    const overlayEl = this.$refs.annOverlay;
                    if (!overlayEl) return;
                    overlayEl.innerHTML = '';
                    if (!this.canvasWidth || !this.canvasHeight || !this.pageWidthPts) return;
                    const scale = this.scale;
                    this.pageAnnotations.forEach((ann) => {
                        if (this.isDrawn(ann)) {
                            this.drawAnnotationElement(ann, overlayEl, scale);
                        }
                    });
                },

                drawAnnotationElement(ann, overlayEl, scale) {
                    const type      = String(ann.type || 'text').toLowerCase();
                    const hasBounds = Number(ann.pdfWidth) > 0 && Number(ann.pdfHeight) > 0;

                    // Resolve bounding box (bottom-origin PDF coords → CSS top-origin)
                    let cssLeft, cssTop, cssWidth = null, cssHeight = null;
                    const box = this.resolveAnnBox(ann);
                    if (box) {
                        cssLeft   = box.x * scale;
                        cssTop    = this.canvasHeight - (box.y + box.h) * scale;
                        cssWidth  = Math.max(2, box.w * scale);
                        cssHeight = Math.max(2, box.h * scale);
                    } else if (Number.isFinite(Number(ann.pdfX)) && Number.isFinite(Number(ann.pdfY))) {
                        // Unbounded text: pdfY is the bottom-origin anchor point
                        cssLeft = Number(ann.pdfX) * scale;
                        cssTop  = this.canvasHeight - Number(ann.pdfY) * scale;
                    } else {
                        return; // no usable coordinates
                    }

                    const el = document.createElement('div');
                    el.className = 'recon-ann';
                    el.style.left      = cssLeft.toFixed(2) + 'px';
                    el.style.top       = cssTop.toFixed(2)  + 'px';
                    if (cssWidth  !== null) el.style.width  = cssWidth.toFixed(2)  + 'px';
                    if (cssHeight !== null) el.style.height = cssHeight.toFixed(2) + 'px';

                    if (type === 'eraser') {
                        el.style.background = 'white';
                        el.style.border     = 'none';

                    } else if (type === 'shape') {
                        el.style.padding    = '0';
                        el.style.border     = 'none';
                        el.style.background = 'transparent';
                        el.appendChild(this._buildShapeSvgEl(ann));

                    } else if (type === 'table') {
                        el.style.padding    = '0';
                        el.style.border     = 'none';
                        el.style.background = 'transparent';
                        const ns       = 'http://www.w3.org/2000/svg';
                        const tRows    = Math.max(1, parseInt(ann.rows)  || 1);
                        const tCols    = Math.max(1, parseInt(ann.cols)  || 1);
                        const tStroke  = ann.strokeColor || '#000000';
                        const tStrokeW = ann.strokeWidth || 1;
                        const tFill    = ann.fillTransparent ? 'none' : (ann.fillColor || '#ffffff');
                        const tOpacity = ann.opacity !== undefined ? ann.opacity : 1;
                        const svg      = document.createElementNS(ns, 'svg');
                        const hw       = tStrokeW / 2;
                        svg.setAttribute('width', '100%'); svg.setAttribute('height', '100%');
                        svg.setAttribute('viewBox', `${-hw} ${-hw} ${100 + tStrokeW} ${100 + tStrokeW}`);
                        svg.setAttribute('preserveAspectRatio', 'none');
                        svg.style.display = 'block'; svg.style.overflow = 'visible';
                        if (tFill !== 'none') {
                            const bg = document.createElementNS(ns, 'rect');
                            bg.setAttribute('x','0'); bg.setAttribute('y','0');
                            bg.setAttribute('width','100'); bg.setAttribute('height','100');
                            bg.setAttribute('fill', tFill); bg.setAttribute('opacity', String(tOpacity));
                            svg.appendChild(bg);
                        }
                        const g = document.createElementNS(ns, 'g');
                        g.setAttribute('stroke', tStroke); g.setAttribute('stroke-width', String(tStrokeW));
                        g.setAttribute('opacity', String(tOpacity));
                        if (ann.outerBorder !== false) {
                            const r = document.createElementNS(ns, 'rect');
                            r.setAttribute('x','0'); r.setAttribute('y','0');
                            r.setAttribute('width','100'); r.setAttribute('height','100');
                            r.setAttribute('fill','none'); g.appendChild(r);
                        }
                        for (let row = 1; row < tRows; row++) {
                            const y = (row / tRows * 100).toFixed(2);
                            const ln = document.createElementNS(ns, 'line');
                            ln.setAttribute('x1','0'); ln.setAttribute('y1',y);
                            ln.setAttribute('x2','100'); ln.setAttribute('y2',y);
                            ln.setAttribute('fill','none'); g.appendChild(ln);
                        }
                        for (let col = 1; col < tCols; col++) {
                            const x = (col / tCols * 100).toFixed(2);
                            const ln = document.createElementNS(ns, 'line');
                            ln.setAttribute('x1',x); ln.setAttribute('y1','0');
                            ln.setAttribute('x2',x); ln.setAttribute('y2','100');
                            ln.setAttribute('fill','none'); g.appendChild(ln);
                        }
                        svg.appendChild(g);
                        el.appendChild(svg);

                    } else if (type === 'image' || type === 'signature') {
                        el.style.border          = '1.5px dashed #10b981';
                        el.style.background      = 'rgba(16,185,129,0.08)';
                        el.style.display         = 'flex';
                        el.style.alignItems      = 'center';
                        el.style.justifyContent  = 'center';
                        el.style.fontSize        = '11px';
                        el.style.color           = '#10b981';
                        el.style.fontFamily      = 'sans-serif';
                        el.textContent = type === 'signature' ? '[signature]' : '[image]';

                    } else if (type === 'field') {
                        el.style.border         = '1.5px solid #f59e0b';
                        el.style.background     = 'rgba(245,158,11,0.10)';
                        el.style.display        = 'flex';
                        el.style.alignItems     = 'center';
                        el.style.justifyContent = 'center';
                        el.style.fontSize       = '11px';
                        el.style.color          = '#f59e0b';
                        el.style.fontFamily     = 'sans-serif';
                        el.textContent = '[field: ' + (ann.fieldKind || ann.fieldType || '') + ']';

                    } else {
                        // text annotation — exact match to written annotation properties
                        const fontSize      = Number(ann.fontSize) || 12;
                        const fontSizePx    = fontSize * scale;
                        const fontWeight    = ann.fontWeight || 'normal';
                        const fontStyle     = ann.fontStyle  || 'normal';

                        // Prefer the first sourceSpan's embedded_font_name for resolution —
                        // annotation.fontSourceName is sometimes truncated (e.g. "ITCFranklinGothicStd-Dem"
                        // vs the full "ITCFranklinGothicStd-Demi" in the span).
                        const _srcSpansRaw = Array.isArray(ann.sourceSpans) ? ann.sourceSpans : [];
                        const _primarySpanSrcName = _srcSpansRaw.length > 0
                            ? String(_srcSpansRaw[0].embedded_font_name || _srcSpansRaw[0].font || '').trim()
                            : '';
                        const fontFamily = _resolveCssFont(
                            _primarySpanSrcName || ann.fontSourceName,
                            ann.fontFamily
                        );

                        // Resolve font-stretch from embedded font metadata (e.g. condensed)
                        const resolveFontStretch = (srcName) => {
                            if (!_embeddedFonts) return 'normal';
                            const rawName = String(srcName || '').trim();
                            if (!rawName) return 'normal';
                            for (const [k, fd] of Object.entries(_embeddedFonts)) {
                                const cn = String(fd.clean_name || k || '').trim();
                                if (cn.toLowerCase() === rawName.toLowerCase()) {
                                    return fd.css_stretch || 'normal';
                                }
                            }
                            return 'normal';
                        };
                        const fontStretch = resolveFontStretch(
                            _primarySpanSrcName || ann.fontSourceName || ann.fontFamily || ''
                        );

                        const getSpanBBox = (span) => Array.isArray(span?.bbox) && span.bbox.length >= 4 ? span.bbox : null;
                        const getSpanFontSize = (span) => {
                            const value = Number(span?.font_size ?? span?.fontSize);
                            return Number.isFinite(value) && value > 0 ? value : null;
                        };
                        const getSpanColorValue = (span, fallbackColor = '#000000') => {
                            if (span?.hex_color) return String(span.hex_color);
                            if (span?.color !== undefined && span?.color !== null) {
                                if (typeof span.color === 'number') {
                                    return '#' + span.color.toString(16).padStart(6, '0');
                                }
                                const raw = String(span.color).trim();
                                if (raw) return raw;
                            }
                            return fallbackColor;
                        };
                        const getSpanWidth = (span) => {
                            const bbox = getSpanBBox(span);
                            return bbox ? Math.max(0, Number(bbox[2]) - Number(bbox[0])) : 0;
                        };
                        const resolveLineSourceStyle = (lineBBox = null) => {
                            const defaultStyle = {
                                fontFamily,
                                fontSizePx,
                                fontWeight,
                                fontStyle,
                                fontStretch,
                            };
                            if (!_srcSpansRaw.length) return defaultStyle;

                            let candidates = _srcSpansRaw.filter((span) => getSpanBBox(span));
                            if (lineBBox && Array.isArray(lineBBox) && lineBBox.length >= 4) {
                                const lineTop = Number(lineBBox[1]);
                                const lineBottom = Number(lineBBox[3]);
                                candidates = candidates.filter((span) => {
                                    const bbox = getSpanBBox(span);
                                    if (!bbox) return false;
                                    const spanTop = Number(bbox[1]);
                                    const spanBottom = Number(bbox[3]);
                                    return spanBottom >= lineTop - 1 && spanTop <= lineBottom + 1;
                                });
                            }
                            if (!candidates.length) return defaultStyle;

                            const anchorSpan = candidates
                                .slice()
                                .sort((a, b) => getSpanWidth(b) - getSpanWidth(a))[0];
                            const anchorSrcName = String(
                                anchorSpan?.embedded_font_name
                                || anchorSpan?.font
                                || _primarySpanSrcName
                                || ann.fontSourceName
                                || ''
                            ).trim();
                            const anchorFamily = String(
                                anchorSpan?.embedded_font_family
                                || anchorSpan?.fontFamily
                                || anchorSrcName
                                || ann.fontFamily
                                || ''
                            ).trim();
                            const anchorFontSize = getSpanFontSize(anchorSpan) || fontSize;
                            return {
                                fontFamily: _resolveCssFont(anchorSrcName, anchorFamily),
                                fontSizePx: anchorFontSize * scale,
                                fontWeight: anchorSpan?.font_weight || anchorSpan?.fontWeight || (anchorSpan?.bold ? '700' : fontWeight),
                                fontStyle: anchorSpan?.fontStyle || (anchorSpan?.italic ? 'italic' : fontStyle),
                                fontStretch: resolveFontStretch(anchorSrcName || anchorFamily),
                            };
                        };

                        // Helper: apply font/color styles to an element
                        const applyTextStyle = (lineEl, lineHeightPx, styleOverrides = null) => {
                            const style = styleOverrides || resolveLineSourceStyle();
                            lineEl.style.fontFamily  = style.fontFamily;
                            lineEl.style.fontSize    = style.fontSizePx.toFixed(2) + 'px';
                            lineEl.style.fontWeight  = style.fontWeight;
                            lineEl.style.fontStyle   = style.fontStyle;
                            lineEl.style.fontStretch = style.fontStretch;
                            lineEl.style.fontKerning = 'none';
                            lineEl.style.color       = ann.textColor || '#000000';
                            lineEl.style.background  = 'transparent';
                            lineEl.style.padding     = '0';
                            lineEl.style.margin      = '0';
                            lineEl.style.whiteSpace  = 'pre';
                            lineEl.style.overflow    = 'visible';
                            lineEl.style.lineHeight  = lineHeightPx.toFixed(2) + 'px';
                        };

                        // ── Positioning principle ──
                        // Python places each line's baseline at: rect.y0 + size * font.ascender
                        // CSS places baseline at: el.style.top + fontBoundingBoxAscent
                        // Since fontBoundingBoxAscent ≈ size * font.ascender for correctly-loaded fonts,
                        // setting el.style.top = rect.y0_css cancels to place the baseline correctly.
                        // rect.y0_css = canvasHeight - (pdfY + pdfHeight) * scale
                        //             = the INITIAL cssTop already computed above for this element.

                        // For multi-line promoted annotations, Python uses sourceLineBBoxes to
                        // place each line at its exact extracted position — NOT uniform lineHeight.
                        // The translation Python applies: translate_y = rect.y0 - min(bboxes[i][1])
                        // simplifies to: line_i top = rect.y0_css + (bbox[i][1] - bbox[0][1]) * scale
                        const srcLines  = Array.isArray(ann.sourceTextLines)  ? ann.sourceTextLines  : null;
                        const srcBBoxesRaw = Array.isArray(ann.sourceLineBBoxes) ? ann.sourceLineBBoxes : null;
                        const srcBBoxes = (() => {
                            if (!srcBBoxesRaw) return null;
                            if (!srcLines || srcBBoxesRaw.length === srcLines.length) return srcBBoxesRaw;
                            const filtered = srcBBoxesRaw.filter((bbox) => (
                                Array.isArray(bbox)
                                && bbox.length >= 4
                                && (Number(bbox[2]) - Number(bbox[0])) > 1
                            ));
                            return filtered.length === srcLines.length ? filtered : srcBBoxesRaw;
                        })();

                        if (box && srcBBoxes && srcLines &&
                            srcBBoxes.length > 1 && srcBBoxes.length === srcLines.length &&
                            ann.promotedFromExtraction) {

                            const rect_y0_css = this.canvasHeight - (box.y + box.h) * scale;
                            const refY = Number(srcBBoxes[0][1]);  // y0 of first line bbox

                            for (let i = 0; i < srcLines.length; i++) {
                                const bbox = srcBBoxes[i];
                                if (!Array.isArray(bbox) || bbox.length < 4) continue;
                                const lineStyle = resolveLineSourceStyle(bbox);
                                const lineEl  = document.createElement('div');
                                lineEl.className = 'recon-ann';
                                const lineH   = Math.max(lineStyle.fontSizePx, (Number(bbox[3]) - Number(bbox[1])) * scale);
                                lineEl.style.left  = (Number(bbox[0]) * scale).toFixed(2) + 'px';
                                lineEl.style.top   = (rect_y0_css + (Number(bbox[1]) - refY) * scale).toFixed(2) + 'px';
                                lineEl.style.width = Math.max(2, (Number(bbox[2]) - Number(bbox[0])) * scale).toFixed(2) + 'px';
                                applyTextStyle(lineEl, lineH, lineStyle);
                                lineEl.textContent = srcLines[i];
                                _applyExactTextWidthFit(lineEl, {
                                    text: srcLines[i],
                                    targetWidthPx: (Number(bbox[2]) - Number(bbox[0])) * scale,
                                    fontSizePx: lineStyle.fontSizePx,
                                    fontFamily: lineStyle.fontFamily,
                                    fontWeight: lineStyle.fontWeight,
                                    fontStyle: lineStyle.fontStyle,
                                    fontStretch: lineStyle.fontStretch,
                                });
                                overlayEl.appendChild(lineEl);
                            }
                            return;  // skip appending main el
                        }

                        // Single-element (single-line or wrapped) — top = rect.y0_css (already set above).
                        const singleLineStyle = resolveLineSourceStyle(
                            Array.isArray(srcBBoxes) && srcBBoxes.length ? srcBBoxes[0] : null
                        );
                        const lineHeightPx = Number(ann.lineHeight) > 0
                            ? Number(ann.lineHeight) * scale
                            : singleLineStyle.fontSizePx;
                        applyTextStyle(el, lineHeightPx, singleLineStyle);

                        // Leader-dot rows are extracted as individual "." spans at exact x origins.
                        // Rendering them as one flowed string lets the browser recompute spaces,
                        // which collapses the evenly spaced PDF dot cadence.
                        // Mixed-font spans: render as inline <span> children of the container so
                        // the browser uses actual font advance-widths for spacing — exactly like the PDF.
                        // Absolute-per-span positioning created gaps because the bbox width includes
                        // advance space that our font renders slightly shorter than the PDF measured.
                        const srcSpans = _srcSpansRaw;
                        const NUMBERED_FIELD_GUTTER_PTS = 14.4;
                        const getSpanDisplayText = (span) => {
                            if (span && span.render_text !== undefined && span.render_text !== null) {
                                return String(span.render_text);
                            }
                            return String(span?.text ?? span?.rawText ?? '');
                        };
                        const isPureNumericText = (text) => /^\d+$/.test(String(text || '').trim());
                        const isNumberedFieldMarker = (span) => /^\d+[A-Za-z]?$/.test(getSpanDisplayText(span).trim());
                        const isNumberedFieldRow = srcSpans.length > 1
                            && isNumberedFieldMarker(srcSpans[0])
                            && srcSpans.some((span, index) => {
                                if (index < 1) return false;
                                const text = getSpanDisplayText(span).trim();
                                return text && text !== '.' && !isPureNumericText(text);
                            });
                        const hasCanonicalRenderedSpans = srcSpans.some((span) =>
                            span && span.render_text !== undefined && span.render_text !== null
                        );
                        const reconstructedAbsoluteSpanLeadingShiftTexts = new Array(srcSpans.length).fill('');
                        const reconstructedAbsoluteSpanTexts = isNumberedFieldRow
                            ? srcSpans.map(getSpanDisplayText)
                            : (hasCanonicalRenderedSpans ? srcSpans.map(getSpanDisplayText) : (() => {
                            let remaining = String(ann.text || '');
                            const rendered = srcSpans.map((span) => {
                                const coreText = String(span.text || span.rawText || '');
                                if (!coreText) return '';
                                const idx = remaining.indexOf(coreText);
                                if (idx < 0) return coreText;
                                const prefix = remaining.slice(0, idx);
                                remaining = remaining.slice(idx + coreText.length);
                                if (/^\s+$/.test(prefix) && coreText.trim() !== '.') {
                                    return prefix + coreText;
                                }
                                return coreText;
                            });
                            for (let i = 1; i < rendered.length; i++) {
                                const current = rendered[i];
                                const leading = current.match(/^\s+/)?.[0] || '';
                                if (!leading) continue;
                                const previousCore = String(srcSpans[i - 1]?.text || srcSpans[i - 1]?.rawText || '').trim();
                                const currentCore = String(srcSpans[i]?.text || srcSpans[i]?.rawText || '').trim();
                                const previousLooksLikeFieldMarker = /^\d+[A-Za-z]?$/.test(previousCore);
                                if (!previousLooksLikeFieldMarker || currentCore === '.') continue;
                                rendered[i] = current.slice(leading.length);
                                reconstructedAbsoluteSpanLeadingShiftTexts[i] = leading;
                            }
                            return rendered;
                        })());
                        const dotLeaderText = String(ann.text || '');
                        const isDotLeaderRun = srcSpans.length > 1
                            && /^[.\s]+$/.test(dotLeaderText)
                            && srcSpans.every((span) => {
                                const spanText = getSpanDisplayText(span).trim();
                                const origin = Array.isArray(span.origin) ? span.origin : null;
                                return spanText === '.' && origin && origin.length >= 2
                                    && Number.isFinite(Number(origin[0]));
                            });
                        const hasMixedSpans = srcSpans.length > 1 && srcSpans.some(s => {
                            if (Math.abs((Number(s.fontSize) || fontSize) - fontSize) > 0.1) return true;
                            if (String(s.fontWeight || '400') !== String(fontWeight)) return true;
                            // Normalize PostScript font name suffixes before comparing
                            // (e.g. TimesNewRomanPSMT ≡ TimesNewRoman, same physical font).
                            const _normPs = (n) => String(n || '').trim().replace(/PSMT$/i, '').replace(/PS(-\w+MT)$/i, '$1').trim();
                            const spanFont = _normPs(s.embedded_font_name || s.font || '');
                            const primaryFont = _normPs(_primarySpanSrcName || String(ann.fontSourceName || ''));
                            return spanFont.toLowerCase() !== primaryFont.toLowerCase();
                        });
                        const hasPerSpanColors = srcSpans.length > 1 && srcSpans.some((span) => (
                            getSpanColorValue(span, ann.textColor || '#000000').toLowerCase() !==
                            String(ann.textColor || '#000000').toLowerCase()
                        ));
                        const canPositionMixedSpansAbsolutely = srcSpans.length > 1 && srcSpans.every((span) => {
                            const origin = Array.isArray(span.origin) ? span.origin : null;
                            return origin && origin.length >= 2 && Number.isFinite(Number(origin[0]));
                        });
                        const hasInlineLeaderSpans = srcSpans.length > 2
                            && srcSpans.some((span) => getSpanDisplayText(span).trim() === '.')
                            && srcSpans.some((span) => {
                                const text = getSpanDisplayText(span).trim();
                                return text && text !== '.';
                            });
                        const getNumberedFieldGutterShiftPx = (index) => {
                            if (index < 1) return 0;
                            const previousSpan = srcSpans[index - 1];
                            const currentSpan = srcSpans[index];
                            const previousText = getSpanDisplayText(previousSpan).trim();
                            const currentText = getSpanDisplayText(currentSpan).trim();
                            if (!/^\d+[A-Za-z]?$/.test(previousText) || !currentText || currentText === '.' || isPureNumericText(currentText)) {
                                return 0;
                            }
                            const previousBBox = Array.isArray(previousSpan?.bbox) ? previousSpan.bbox : null;
                            const currentOrigin = Array.isArray(currentSpan?.origin) ? currentSpan.origin : null;
                            if (!previousBBox || previousBBox.length < 4 || !currentOrigin || currentOrigin.length < 2) {
                                return 0;
                            }
                            const contiguousGapPts = Number(currentOrigin[0]) - Number(previousBBox[2]);
                            if (contiguousGapPts > 1.0) {
                                return 0;
                            }
                            // Numbered field labels on this form use a fixed extraction gutter
                            // of 14.4pt between the marker and the label text.
                            return NUMBERED_FIELD_GUTTER_PTS * scale;
                        };
                        if (isDotLeaderRun) {
                            const baseLeftPts = box
                                ? Number(box.x)
                                : Number(ann.pdfX ?? srcSpans[0]?.origin?.[0] ?? 0);
                            el.style.overflow = 'visible';
                            el.style.lineHeight = fontSizePx.toFixed(2) + 'px';
                            srcSpans.forEach((span, index) => {
                                const origin = Array.isArray(span.origin) ? span.origin : null;
                                if (!origin) return;

                                const spanEl = document.createElement('span');
                                const spanSrcName = span.embedded_font_name || span.font || '';
                                const spanFontPx  = (Number(span.fontSize) || fontSize) * scale;
                                let spanStretch = 'normal';
                                if (_embeddedFonts && spanSrcName) {
                                    for (const [k, fd] of Object.entries(_embeddedFonts)) {
                                        if (String(fd.clean_name || k).trim().toLowerCase() === spanSrcName.toLowerCase()) {
                                            spanStretch = fd.css_stretch || 'normal';
                                            break;
                                        }
                                    }
                                }

                                spanEl.style.position    = 'absolute';
                                spanEl.style.left        = ((Number(origin[0]) - baseLeftPts) * scale).toFixed(2) + 'px';
                                spanEl.style.top         = '0px';
                                spanEl.style.fontFamily  = _resolveCssFont(spanSrcName, span.fontFamily || spanSrcName);
                                spanEl.style.fontSize    = spanFontPx.toFixed(2) + 'px';
                                spanEl.style.fontWeight  = span.fontWeight || fontWeight;
                                spanEl.style.fontStyle   = span.fontStyle || fontStyle;
                                spanEl.style.fontStretch = spanStretch;
                                spanEl.style.fontKerning = 'none';
                                spanEl.style.lineHeight  = spanFontPx.toFixed(2) + 'px';
                                spanEl.style.whiteSpace  = 'pre';
                                spanEl.style.color       = ann.textColor || '#000000';
                                const renderedSpanText = reconstructedAbsoluteSpanTexts[index] || getSpanDisplayText(span);
                                spanEl.textContent = renderedSpanText;
                                const spanBBox = Array.isArray(span.bbox) ? span.bbox : null;
                                if (
                                    spanBBox
                                    && spanBBox.length >= 4
                                    && spanEl.textContent
                                    && !/^\s|\s$/.test(renderedSpanText)
                                ) {
                                    _applyExactTextWidthFit(spanEl, {
                                        text: spanEl.textContent,
                                        targetWidthPx: (Number(spanBBox[2]) - Number(spanBBox[0])) * scale,
                                        fontSizePx: spanFontPx,
                                        fontFamily: spanEl.style.fontFamily,
                                        fontWeight: spanEl.style.fontWeight,
                                        fontStyle: spanEl.style.fontStyle,
                                        fontStretch: spanEl.style.fontStretch,
                                    });
                                }
                                el.appendChild(spanEl);
                            });
                        } else if (canPositionMixedSpansAbsolutely && (hasPerSpanColors || hasMixedSpans || hasInlineLeaderSpans)) {
                            const baseLeftPts = box
                                ? Number(box.x)
                                : Number(ann.pdfX ?? srcSpans[0]?.origin?.[0] ?? 0);
                            el.style.overflow = 'visible';
                            el.style.lineHeight = 'normal';
                            srcSpans.forEach((span, index) => {
                                const origin = Array.isArray(span.origin) ? span.origin : null;
                                if (!origin) return;

                                const spanEl = document.createElement('span');
                                const spanSrcName = span.embedded_font_name || span.font || '';
                                const spanFontPx  = (Number(span.font_size ?? span.fontSize) || fontSize) * scale;
                                const spanFamily  = _resolveCssFont(spanSrcName, span.embedded_font_family || span.fontFamily || spanSrcName);
                                const spanWeight  = span.font_weight || span.fontWeight || (span.bold ? '700' : fontWeight);
                                const spanStyle   = span.fontStyle || (span.italic ? 'italic' : fontStyle);
                                const spanColor   = getSpanColorValue(span, ann.textColor || '#000000');
                                let spanStretch = 'normal';
                                if (_embeddedFonts && spanSrcName) {
                                    for (const [k, fd] of Object.entries(_embeddedFonts)) {
                                        if (String(fd.clean_name || k).trim().toLowerCase() === spanSrcName.toLowerCase()) {
                                            spanStretch = fd.css_stretch || 'normal';
                                            break;
                                        }
                                    }
                                }

                                spanEl.style.position    = 'absolute';
                                spanEl.style.left        = ((Number(origin[0]) - baseLeftPts) * scale).toFixed(2) + 'px';
                                spanEl.style.top         = '0px';
                                spanEl.style.fontFamily  = spanFamily;
                                spanEl.style.fontSize    = spanFontPx.toFixed(2) + 'px';
                                spanEl.style.fontWeight  = String(spanWeight);
                                spanEl.style.fontStyle   = spanStyle;
                                spanEl.style.fontStretch = spanStretch;
                                spanEl.style.fontKerning = 'none';
                                spanEl.style.lineHeight  = spanFontPx.toFixed(2) + 'px';
                                spanEl.style.whiteSpace  = 'pre';
                                spanEl.style.color       = spanColor;
                                const renderedSpanText = reconstructedAbsoluteSpanTexts[index] || getSpanDisplayText(span);
                                const shiftedLeadingWhitespace = reconstructedAbsoluteSpanLeadingShiftTexts[index] || '';
                                const shiftedLeftPx = (() => {
                                    const numberedFieldGutterShiftPx = getNumberedFieldGutterShiftPx(index);
                                    if (numberedFieldGutterShiftPx > 0) {
                                        return numberedFieldGutterShiftPx;
                                    }
                                    return shiftedLeadingWhitespace
                                        ? _measureExactTextDomWidth(
                                            shiftedLeadingWhitespace,
                                            spanFontPx,
                                            spanFamily,
                                            String(spanWeight),
                                            spanStyle,
                                            spanStretch
                                        )
                                        : 0;
                                })();
                                spanEl.style.left        = (((Number(origin[0]) - baseLeftPts) * scale) + shiftedLeftPx).toFixed(2) + 'px';
                                spanEl.textContent = renderedSpanText;
                                const spanBBox = Array.isArray(span.bbox) ? span.bbox : null;
                                if (
                                    spanBBox
                                    && spanBBox.length >= 4
                                    && spanEl.textContent
                                    && !/^\s|\s$/.test(renderedSpanText)
                                ) {
                                    _applyExactTextWidthFit(spanEl, {
                                        text: spanEl.textContent,
                                        targetWidthPx: (Number(spanBBox[2]) - Number(spanBBox[0])) * scale,
                                        fontSizePx: spanFontPx,
                                        fontFamily: spanFamily,
                                        fontWeight: spanEl.style.fontWeight,
                                        fontStyle: spanStyle,
                                        fontStretch: spanStretch,
                                    });
                                }
                                el.appendChild(spanEl);
                            });
                        } else if (hasMixedSpans) {
                            // Container: flex baseline so different-sized spans share a common baseline
                            el.style.display    = 'flex';
                            el.style.alignItems = 'baseline';
                            el.style.flexWrap   = 'nowrap';
                            el.style.lineHeight = 'normal';
                            // Reconstruct per-span text from ann.text so inter-span whitespace
                            // (e.g. the space between "Sequence No." and "60") is preserved.
                            // Stored span.text fields are trimmed; ann.text has the full string.
                            let annTextRemaining = String(ann.text || '');
                            for (let si = 0; si < srcSpans.length; si++) {
                                const span        = srcSpans[si];
                                const spanEl      = document.createElement('span');
                                const spanSrcName = span.embedded_font_name || span.font || '';
                                const spanFontPx  = (Number(span.fontSize) || fontSize) * scale;
                                const spanFamily  = _resolveCssFont(spanSrcName, span.fontFamily || spanSrcName);
                                const spanWeight  = span.fontWeight || 'normal';
                                const spanStyle   = span.fontStyle  || 'normal';
                                let spanStretch = 'normal';
                                if (_embeddedFonts && spanSrcName) {
                                    for (const [k, fd] of Object.entries(_embeddedFonts)) {
                                        if (String(fd.clean_name || k).trim().toLowerCase() === spanSrcName.toLowerCase()) {
                                            spanStretch = fd.css_stretch || 'normal';
                                            break;
                                        }
                                    }
                                }
                                spanEl.style.fontFamily  = spanFamily;
                                spanEl.style.fontSize    = spanFontPx.toFixed(2) + 'px';
                                spanEl.style.fontWeight  = spanWeight;
                                spanEl.style.fontStyle   = spanStyle;
                                spanEl.style.fontStretch = spanStretch;
                                spanEl.style.fontKerning = 'none';
                                spanEl.style.color       = getSpanColorValue(span, ann.textColor || '#000000');
                                spanEl.style.whiteSpace  = 'pre';
                                // Take everything from annTextRemaining up to where the
                                // next span's text starts, so trailing spaces/gaps are kept.
                                const nextSpanText = si + 1 < srcSpans.length
                                    ? String(srcSpans[si + 1].text || '') : '';
                                let chunkText;
                                if (!nextSpanText) {
                                    chunkText = annTextRemaining;
                                } else {
                                    const nextIdx = annTextRemaining.indexOf(nextSpanText);
                                    if (nextIdx >= 0) {
                                        chunkText = annTextRemaining.slice(0, nextIdx);
                                        annTextRemaining = annTextRemaining.slice(nextIdx);
                                    } else {
                                        chunkText = String(span.text || '');
                                        annTextRemaining = annTextRemaining.slice(chunkText.length);
                                    }
                                }
                                spanEl.textContent = chunkText;
                                el.appendChild(spanEl);
                            }
                        } else {
                            const primarySpan = srcSpans[0] || null;
                            const primaryRotation = Number(primarySpan?.rotation ?? ann.rotation ?? 0);
                            const isQuarterTurn = Math.abs(Math.abs(primaryRotation) - 90) < 1;
                            if (primarySpan && isQuarterTurn && box) {
                                const rotatedSpan = document.createElement('span');
                                rotatedSpan.textContent = String(ann.text || '');
                                rotatedSpan.style.position = 'absolute';
                                rotatedSpan.style.left = '0px';
                                rotatedSpan.style.top = '0px';
                                rotatedSpan.style.whiteSpace = 'pre';
                                rotatedSpan.style.fontFamily = singleLineStyle.fontFamily;
                                rotatedSpan.style.fontSize = singleLineStyle.fontSizePx.toFixed(2) + 'px';
                                rotatedSpan.style.fontWeight = singleLineStyle.fontWeight;
                                rotatedSpan.style.fontStyle = singleLineStyle.fontStyle;
                                rotatedSpan.style.fontStretch = singleLineStyle.fontStretch;
                                rotatedSpan.style.fontKerning = 'none';
                                rotatedSpan.style.lineHeight = singleLineStyle.fontSizePx.toFixed(2) + 'px';
                                rotatedSpan.style.color = ann.textColor || '#000000';
                                rotatedSpan.style.transformOrigin = 'left top';
                                rotatedSpan.style.transform = primaryRotation < 0
                                    ? `translate(0px, ${box.h.toFixed(2)}px) rotate(${primaryRotation}deg)`
                                    : `translate(${box.w.toFixed(2)}px, 0px) rotate(${primaryRotation}deg)`;
                                el.style.overflow = 'visible';
                                el.appendChild(rotatedSpan);
                            } else {
                            el.textContent = ann.text || '';
                            if (cssWidth !== null) {
                                _applyExactTextWidthFit(el, {
                                    text: ann.text || '',
                                    targetWidthPx: cssWidth,
                                    fontSizePx: singleLineStyle.fontSizePx,
                                    fontFamily: singleLineStyle.fontFamily,
                                    fontWeight: singleLineStyle.fontWeight,
                                    fontStyle: singleLineStyle.fontStyle,
                                    fontStretch: singleLineStyle.fontStretch,
                                });
                            }
                            }
                        }
                    }

                    overlayEl.appendChild(el);
                },

                _buildShapeSvgEl(shapeLike) {
                    const ns    = 'http://www.w3.org/2000/svg';
                    const mkEl  = (tag) => document.createElementNS(ns, tag);
                    const sw    = Math.max(0, Number(shapeLike?.strokeWidth) || 0);
                    const hasSt = !shapeLike?.strokeTransparent && sw > 0
                                  && String(shapeLike?.strokeColor || '').trim().toLowerCase() !== '';
                    const inset = hasSt ? Math.min(45, sw / 2) : 0;
                    const rawOp = Number(shapeLike?.opacity);
                    const op    = Number.isFinite(rawOp)
                                  ? Math.max(0, Math.min(1, rawOp > 1 ? rawOp / 100 : rawOp)) : 1;
                    const sc    = hasSt ? String(shapeLike.strokeColor || '#000000') : 'transparent';
                    const fc    = shapeLike?.fillTransparent ? 'transparent' : String(shapeLike?.fillColor || '#000000');
                    const minE  = inset;
                    const maxE  = 100 - inset;
                    const inner = Math.max(0, 100 - inset * 2);
                    const mapC  = (v) => Number((inset + (inner * (Math.max(0, Math.min(100, Number(v) || 0)) / 100))).toFixed(3));

                    const svg = mkEl('svg');
                    svg.setAttribute('width', '100%');
                    svg.setAttribute('height', '100%');
                    svg.setAttribute('viewBox', `${-inset} ${-inset} ${100 + inset * 2} ${100 + inset * 2}`);
                    svg.setAttribute('preserveAspectRatio', 'none');
                    svg.style.display = 'block'; svg.style.overflow = 'visible';

                    const sty = (el) => {
                        el.setAttribute('fill', fc); el.setAttribute('stroke', sc);
                        if (sw > 0) el.setAttribute('stroke-width', String(sw));
                        el.setAttribute('opacity', String(op));
                        return el;
                    };

                    const sType = String(shapeLike?.shapeType || 'rect');

                    if (sType === 'circle' || sType === 'ellipse') {
                        const e = sty(mkEl('ellipse'));
                        e.setAttribute('cx','50'); e.setAttribute('cy','50');
                        e.setAttribute('rx', String(Math.max(0, 50 - inset)));
                        e.setAttribute('ry', String(Math.max(0, 50 - inset)));
                        svg.appendChild(e);
                    } else if (sType === 'triangle') {
                        const t = sty(mkEl('polygon'));
                        t.setAttribute('points', `50 ${minE}, ${maxE} ${maxE}, ${minE} ${maxE}`);
                        t.setAttribute('stroke-linejoin', 'round');
                        svg.appendChild(t);
                    } else if (sType === 'x') {
                        const g = mkEl('g');
                        [[minE,minE,maxE,maxE],[maxE,minE,minE,maxE]].forEach(([x1,y1,x2,y2]) => {
                            const ln = sty(mkEl('line'));
                            ln.setAttribute('fill','none');
                            ln.setAttribute('x1',String(x1)); ln.setAttribute('y1',String(y1));
                            ln.setAttribute('x2',String(x2)); ln.setAttribute('y2',String(y2));
                            ln.setAttribute('stroke-linecap','round');
                            g.appendChild(ln);
                        });
                        svg.appendChild(g);
                    } else if (sType === 'line') {
                        const startX = Number(shapeLike?.lineStartX ?? 0);
                        const startY = Number(shapeLike?.lineStartY ?? 1);
                        const endX   = Number(shapeLike?.lineEndX   ?? 1);
                        const endY   = Number(shapeLike?.lineEndY   ?? 0);
                        const ln = sty(mkEl('line'));
                        ln.setAttribute('fill','none');
                        ln.setAttribute('stroke-linecap','round');
                        ln.setAttribute('vector-effect','non-scaling-stroke');
                        ln.setAttribute('x1', String(Number((startX * 100).toFixed(3))));
                        ln.setAttribute('y1', String(Number((startY * 100).toFixed(3))));
                        ln.setAttribute('x2', String(Number((endX   * 100).toFixed(3))));
                        ln.setAttribute('y2', String(Number((endY   * 100).toFixed(3))));
                        svg.appendChild(ln);
                    } else if (sType === 'checkmark') {
                        const poly = sty(mkEl('polyline'));
                        poly.setAttribute('fill','none');
                        poly.setAttribute('points', `${mapC(15)} ${mapC(50)}, ${mapC(40)} ${mapC(75)}, ${mapC(85)} ${mapC(15)}`);
                        poly.setAttribute('stroke-linecap','round'); poly.setAttribute('stroke-linejoin','round');
                        poly.setAttribute('vector-effect','non-scaling-stroke');
                        svg.appendChild(poly);
                    } else if (sType === 'star') {
                        const star = sty(mkEl('polygon'));
                        star.setAttribute('points', [[50,5],[61,38],[95,38],[68,58],[79,91],[50,71],[21,91],[32,58],[5,38],[39,38]].map(([x,y]) => `${mapC(x)} ${mapC(y)}`).join(', '));
                        star.setAttribute('stroke-linejoin','round');
                        svg.appendChild(star);
                    } else if (sType === 'polygon') {
                        const hex = sty(mkEl('polygon'));
                        hex.setAttribute('points', [[50,5],[90,27],[90,73],[50,95],[10,73],[10,27]].map(([x,y]) => `${mapC(x)} ${mapC(y)}`).join(', '));
                        hex.setAttribute('stroke-linejoin','round');
                        svg.appendChild(hex);
                    } else if (sType === 'arrow') {
                        const g = mkEl('g');
                        const ln = sty(mkEl('line'));
                        ln.setAttribute('fill','none');
                        ln.setAttribute('x1', String(minE)); ln.setAttribute('y1','50');
                        ln.setAttribute('x2', String(mapC(82))); ln.setAttribute('y2','50');
                        ln.setAttribute('stroke-linecap','round'); ln.setAttribute('vector-effect','non-scaling-stroke');
                        const head = sty(mkEl('polyline'));
                        head.setAttribute('fill','none');
                        head.setAttribute('points', `${mapC(64)} ${mapC(32)}, ${maxE} 50, ${mapC(64)} ${mapC(68)}`);
                        head.setAttribute('stroke-linecap','round'); head.setAttribute('stroke-linejoin','round');
                        head.setAttribute('vector-effect','non-scaling-stroke');
                        g.appendChild(ln); g.appendChild(head);
                        svg.appendChild(g);
                    } else {
                        // rect (default)
                        const r = sty(mkEl('rect'));
                        r.setAttribute('x', String(minE)); r.setAttribute('y', String(minE));
                        r.setAttribute('width', String(inner)); r.setAttribute('height', String(inner));
                        svg.appendChild(r);
                    }

                    return svg;
                },

                /* ─────────────────────────────────────────────── */
                /* DISPLAY HELPERS                                 */
                /* ─────────────────────────────────────────────── */

                annotationPreview(ann) {
                    const type = String(ann.type || 'text').toLowerCase();
                    if (type === 'text' || !ann.type) {
                        return String(ann.text || '').trim().slice(0, 80) || '(empty text)';
                    }
                    if (type === 'image')     return '[image]';
                    if (type === 'signature') return '[signature]';
                    if (type === 'eraser')    return '[eraser]';
                    if (type === 'shape')     return '[' + (ann.shapeType || 'shape') + ']';
                    if (type === 'field')     return '[field: ' + (ann.fieldType || '') + ']';
                    if (type === 'table')     return '[table]';
                    return '[' + type + ']';
                },

                positionSummary(ann) {
                    const box = this.resolveAnnBox(ann);
                    if (!box) return 'no position data';
                    return `x:${box.x.toFixed(1)} y:${box.y.toFixed(1)} w:${box.w.toFixed(1)} h:${box.h.toFixed(1)}`;
                },

                typeColorClass(type) {
                    const map = {
                        text:      'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
                        shape:     'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
                        field:     'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
                        image:     'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
                        eraser:    'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400',
                        signature: 'bg-pink-100 text-pink-700 dark:bg-pink-900/30 dark:text-pink-400',
                        table:     'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
                    };
                    return map[String(type || 'text').toLowerCase()]
                        || 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400';
                },

            };
        }
    </script>
</x-filament-panels::page>
