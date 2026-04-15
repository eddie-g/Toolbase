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
            <div class="flex items-center gap-2">
                <a href="{{ route('filament.admin.pages.pdf-reconstruction-2') }}">
                    <x-filament::button color="gray" size="sm" icon="heroicon-o-sparkles">Reconstruction 2</x-filament::button>
                </a>
                <a href="{{ route('filament.admin.pages.run-pdf-tests') }}">
                    <x-filament::button color="gray" size="sm" icon="heroicon-o-arrow-left">PDF Tests</x-filament::button>
                </a>
            </div>
        </div>

        {{-- ═══════════════════════════════════════════════════════════
             ADMIN: ALL DOCUMENTS LIST
        ═══════════════════════════════════════════════════════════ --}}
        <div class="bg-white dark:bg-gray-800 rounded-2xl ring-1 ring-gray-950/5 dark:ring-white/10 mb-6 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between gap-3">
                <span class="text-sm font-semibold text-gray-900 dark:text-white">All Documents</span>
                <span class="text-xs text-gray-400 dark:text-gray-500">{{ $documents->count() }} total · click a row to load</span>
            </div>
            <div class="overflow-x-auto" style="max-height: 260px; overflow-y: auto;">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-gray-50 dark:bg-gray-900 z-10">
                        <tr>
                            <th class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 px-4 py-2">ID</th>
                            <th class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 px-4 py-2">Name</th>
                            <th class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 px-4 py-2">User</th>
                            <th class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 px-4 py-2">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($documents as $doc)
                            <tr
                                class="cursor-pointer hover:bg-primary-50 dark:hover:bg-primary-900/20 transition-colors"
                                x-on:click="docIdInput = {{ $doc->id }}; loadDocument()"
                            >
                                <td class="px-4 py-2 font-mono text-xs text-gray-600 dark:text-gray-400 whitespace-nowrap">#{{ $doc->id }}</td>
                                <td class="px-4 py-2 text-gray-900 dark:text-white max-w-xs truncate" title="{{ $doc->original_name }}">{{ $doc->original_name }}</td>
                                <td class="px-4 py-2 text-gray-500 dark:text-gray-400 whitespace-nowrap text-xs">
                                    @if($doc->user)
                                        {{ $doc->user->name }}
                                    @else
                                        <span class="text-gray-300 dark:text-gray-600">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-gray-400 dark:text-gray-500 whitespace-nowrap text-xs">{{ $doc->created_at->format('Y-m-d') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-6 text-center text-sm text-gray-400 dark:text-gray-500">No documents found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
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
                                <button type="button"
                                    x-on:click="toggleDrawAcro()"
                                    x-bind:class="drawAcro
                                        ? 'bg-blue-600 text-white hover:bg-blue-700'
                                        : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'"
                                    class="text-xs font-semibold px-2.5 py-1 rounded-lg transition-colors whitespace-nowrap">
                                    <span x-text="drawAcro ? 'Draw Acro ✓' : 'Draw Acro'"></span>
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

                            {{-- Compare original overlay toggle --}}
                            <button type="button"
                                x-on:click="toggleOriginalOverlay()"
                                x-bind:class="splitView
                                    ? 'bg-primary-100 dark:bg-primary-900/40 text-primary-600 dark:text-primary-400 ring-1 ring-primary-400'
                                    : 'text-gray-400 dark:text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'"
                                class="p-1 rounded transition-colors"
                                title="Toggle original PDF overlay">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 0v10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"/></svg>
                            </button>
                        </div>

                        {{-- Status strip --}}
                        <template x-if="drawnCount > 0 || splitView || (drawAcro && pageAcroWidgets.length > 0) || acroLoading || acroError">
                            <div class="px-4 py-1.5 bg-success-50 dark:bg-success-900/20 border-b border-success-100 dark:border-success-800 text-xs text-success-600 dark:text-success-400 flex items-center gap-2 flex-wrap">
                                <svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                <template x-if="drawnCount > 0">
                                    <span x-text="drawnCount + ' annotation(s) as text overlay'"></span>
                                </template>
                                <template x-if="drawAcro && pageAcroWidgets.length > 0">
                                    <span class="text-blue-600 dark:text-blue-400 font-medium" x-text="(drawnCount > 0 ? '· ' : '') + pageAcroWidgets.length + ' Acro field(s)'"></span>
                                </template>
                                <template x-if="drawAcro && acroLoading">
                                    <span class="text-blue-600 dark:text-blue-400 font-medium" x-text="(drawnCount > 0 || pageAcroWidgets.length > 0 ? '· ' : '') + 'Loading AcroForm fields…'"></span>
                                </template>
                                <template x-if="acroError">
                                    <span class="text-danger-600 dark:text-danger-400 font-medium" x-text="((drawnCount > 0 || pageAcroWidgets.length > 0 || acroLoading) ? '· ' : '') + 'Acro error: ' + acroError"></span>
                                </template>
                                <template x-if="splitView">
                                    <span class="text-primary-600 dark:text-primary-400 font-medium" x-text="((drawnCount > 0 || pageAcroWidgets.length > 0 || acroLoading || acroError) ? '· ' : '') + 'Overlay: original PDF'"></span>
                                </template>
                                <template x-if="splitView">
                                    <span class="flex items-center gap-1.5 ml-1">
                                        <button type="button"
                                                x-on:click="origVisible = !origVisible"
                                                x-bind:class="origVisible ? 'text-primary-600 dark:text-primary-400' : 'text-gray-400 dark:text-gray-500'"
                                                class="transition-colors text-xs font-medium" title="Toggle original visibility">
                                            <span x-text="origVisible ? '👁 Visible' : '👁 Hidden'"></span>
                                        </button>
                                        <input type="range" min="0" max="100" step="5"
                                               x-model="origOpacity"
                                               class="w-24 h-1.5 accent-primary-600 cursor-pointer"
                                               title="Original PDF opacity">
                                        <span class="text-primary-700 dark:text-primary-300 tabular-nums w-8 text-right" x-text="origOpacity + '%'"></span>
                                    </span>
                                </template>
                                <template x-if="splitView">
                                    <button type="button"
                                            x-on:click="splitView = false"
                                            class="ml-auto text-xs font-medium text-danger-500 hover:text-danger-700 dark:text-danger-400 dark:hover:text-danger-300 transition-colors">
                                        Close overlay
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
                             class="relative overflow-auto bg-gray-100 dark:bg-gray-950 flex justify-center items-start py-4 px-4"
                             x-bind:class="panMode ? (_panDragging ? 'cursor-grabbing' : 'cursor-grab') : ''"
                             x-bind:style="panMode ? 'max-height:75vh;user-select:none;touch-action:none;' : 'max-height:75vh;'"
                             x-on:pointerdown="panStart($event)"
                             x-on:pointermove="panMove($event)"
                             x-on:pointerup="panEnd()"
                             x-on:pointercancel="panEnd()">
                            <div class="flex-shrink-0">
                                <div class="relative inline-block" x-bind:style="'width:' + canvasWidth + 'px; height:' + canvasHeight + 'px;'">
                                    <canvas x-ref="pdfCanvas"
                                            class="block shadow-lg"
                                            x-bind:width="canvasWidth"
                                            x-bind:height="canvasHeight">
                                    </canvas>
                                    <div x-ref="annOverlay"
                                         style="position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;overflow:visible;">
                                    </div>
                                    {{-- Original PDF overlay canvas --}}
                                    <canvas x-ref="origCanvas"
                                            x-show="splitView"
                                            x-cloak
                                            x-bind:style="'position:absolute;top:0;left:0;pointer-events:none;opacity:' + (origVisible ? origOpacity / 100 : 0)"
                                            x-bind:width="canvasWidth"
                                            x-bind:height="canvasHeight">
                                    </canvas>
                                </div>
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
                            <div class="flex items-center gap-2 flex-wrap justify-end">
                                <label class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400 cursor-pointer select-none">
                                    <input type="checkbox" x-model="filterCurrentPage" class="rounded text-primary-600">
                                    <span>This page</span>
                                </label>
                                <label class="flex items-center gap-1.5 text-xs cursor-pointer select-none"
                                       x-bind:class="filterFlagged ? 'text-red-500 dark:text-red-400' : 'text-gray-500 dark:text-gray-400'">
                                    <input type="checkbox" x-model="filterFlagged" class="rounded text-red-500">
                                    <span>⚑ Flagged</span>
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
                                            <span class="inline-flex px-1.5 py-0.5 rounded text-xs font-mono text-gray-400 dark:text-gray-500 select-all"
                                                  x-text="ann.id || ann.db_id || ''">
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
                                    <div class="mt-2 flex items-center gap-2 flex-wrap">
                                        <button type="button"
                                            x-on:click="openDebugModal(ann)"
                                            class="text-xs px-2 py-0.5 rounded bg-blue-50 text-blue-600 hover:bg-blue-100 dark:bg-blue-900/30 dark:text-blue-400 dark:hover:bg-blue-900/60 font-medium transition-colors">
                                            Inspect
                                        </button>
                                        <button type="button"
                                            x-on:click="openFlagModal(ann)"
                                            x-bind:class="ann._flagged
                                                ? 'bg-red-100 text-red-600 hover:bg-red-200 dark:bg-red-900/40 dark:text-red-400 dark:hover:bg-red-900/70'
                                                : 'bg-gray-100 text-gray-500 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-400 dark:hover:bg-gray-600'"
                                            class="text-xs px-2 py-0.5 rounded font-medium transition-colors">
                                            <span x-text="ann._flagged ? '⚑ Flagged' : 'Flag'"></span>
                                        </button>
                                        <template x-if="ann._flagged">
                                            <a :href="'{{ url('/documents') }}/' + document.id + '/edit'"
                                               target="_blank"
                                               class="text-xs px-2 py-0.5 rounded bg-green-50 text-green-700 hover:bg-green-100 dark:bg-green-900/30 dark:text-green-400 dark:hover:bg-green-900/60 font-medium transition-colors">
                                                Fix in Editor →
                                            </a>
                                        </template>
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

    {{-- ────────────────────────────────────────────────────────── --}}
    {{-- ANNOTATION INSPECT DRAWER (slides in from right)          --}}
    {{-- ────────────────────────────────────────────────────────── --}}
    <template x-if="debugModal">
        <div class="fixed inset-0 z-[200] flex justify-end"
             x-on:keydown.escape.window="debugModal = false">
            {{-- Backdrop --}}
            <div class="absolute inset-0 bg-black/40"
                 x-on:click="debugModal = false"></div>
            {{-- Drawer — full viewport height, scrolls independently --}}
            <div class="relative z-10 w-full max-w-xl h-screen flex flex-col bg-white dark:bg-gray-900 shadow-2xl">
                {{-- Header --}}
                <div class="shrink-0 flex items-center justify-between px-5 py-3 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/60">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Inspect</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400 font-mono mt-0.5"
                           x-text="'db_id ' + (debugModalAnn?.db_id || (debugModalAnn?.id || '?'))"></p>
                    </div>
                    <button type="button"
                        x-on:click="debugModal = false"
                        class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 p-1 rounded-lg transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                {{-- Body — grows to fill remaining height and scrolls --}}
                <div class="grow overflow-y-auto p-5 space-y-3">
                    <template x-if="debugLoading">
                        <div class="flex items-center justify-center py-12 text-sm text-gray-500 dark:text-gray-400">
                            <svg class="animate-spin h-5 w-5 mr-2 text-primary-500" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4l3-3-3-3v4a8 8 0 00-8 8h4z"/>
                            </svg>
                            Loading…
                        </div>
                    </template>
                    <template x-if="debugError">
                        <div class="rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 p-4 text-sm text-red-600 dark:text-red-400"
                             x-text="debugError">
                        </div>
                    </template>
                    <template x-if="!debugLoading && !debugError && debugData">
                        <div class="space-y-3">
                            <template x-for="[section, value] in Object.entries(debugData)" :key="section">
                                <div class="rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                                    <div class="flex items-center gap-2 px-3 py-1.5 bg-gray-100 dark:bg-gray-800 cursor-pointer select-none"
                                         x-on:click="$el.nextElementSibling.classList.toggle('hidden')">
                                        <span class="text-xs font-semibold font-mono text-gray-600 dark:text-gray-300"
                                              x-text="section"></span>
                                        <span class="ml-auto text-xs text-gray-400"
                                              x-text="Array.isArray(value) ? value.length + ' items' : (typeof value === 'object' && value ? Object.keys(value).length + ' keys' : '')"></span>
                                    </div>
                                    <div class="p-3 bg-white dark:bg-gray-900">
                                        <pre class="text-xs font-mono text-gray-700 dark:text-gray-300 whitespace-pre-wrap break-words leading-relaxed"
                                             x-text="JSON.stringify(value, null, 2)"></pre>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </template>

    {{-- ────────────────────────────────────────────────────────── --}}
    {{-- FLAG ANNOTATION MODAL (inside x-data scope)               --}}
    {{-- ────────────────────────────────────────────────────────── --}}
    <template x-if="flagModal">
        <div class="fixed inset-0 z-[200] flex items-center justify-center p-4"
             x-on:keydown.escape.window="flagModal = false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm"
                 x-on:click="flagModal = false"></div>
            <div class="relative z-10 w-full max-w-lg bg-white dark:bg-gray-900 rounded-2xl shadow-2xl overflow-hidden">
                {{-- Header --}}
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/60">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Flag Annotation</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400 font-mono mt-0.5"
                           x-text="flagModalAnn?.id || flagModalAnn?.db_id || ''">
                        </p>
                    </div>
                    <button type="button" x-on:click="flagModal = false"
                        class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 p-1 rounded-lg transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                {{-- Body --}}
                <div class="p-6 space-y-4 max-h-[75vh] overflow-y-auto">
                    <template x-if="flagModalAnn?._flagged">
                        <div class="rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 flex items-center gap-2 text-sm text-red-600 dark:text-red-400">
                            <span>⚑</span>
                            <span>This annotation is currently flagged as a potential mismatch.</span>
                        </div>
                    </template>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                            Reason for flagging
                            <span class="text-gray-400 font-normal">(optional)</span>
                        </label>
                        <textarea
                            x-model="flagReason"
                            rows="4"
                            placeholder="Describe the mismatch or issue…"
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-sm text-gray-900 dark:text-gray-100 px-3 py-2 focus:ring-2 focus:ring-primary-500 focus:border-transparent outline-none resize-none placeholder:text-gray-400 dark:placeholder:text-gray-500">
                        </textarea>
                    </div>

                    {{-- ── Image upload / paste zone ── --}}
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                Screenshots
                                <span class="text-gray-400 font-normal">(optional, paste or upload)</span>
                            </label>
                            <label class="cursor-pointer text-xs text-primary-600 dark:text-primary-400 hover:underline">
                                + Add file
                                <input type="file" accept="image/*" multiple class="sr-only"
                                    x-on:change="Array.from($event.target.files).forEach(f => _addImgFile(f)); $event.target.value = ''">
                            </label>
                        </div>
                        {{-- Paste drop zone --}}
                        <div
                            tabindex="0"
                            x-on:paste.window="
                                if (!flagModal) return;
                                const items = Array.from($event.clipboardData?.items || []);
                                items.filter(i => i.type.startsWith('image/')).forEach(i => {
                                    const f = i.getAsFile();
                                    if (f) _addImgFile(f);
                                });
                            "
                            class="rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800/40 px-4 py-3 text-center text-xs text-gray-400 dark:text-gray-500 select-none">
                            Ctrl+V / ⌘V to paste a screenshot here
                        </div>
                        {{-- Thumbnail grid --}}
                        <template x-if="flagImages.length > 0">
                            <div class="mt-2 grid grid-cols-3 gap-2">
                                <template x-for="(img, idx) in flagImages" :key="idx">
                                    <div class="relative group rounded-lg overflow-hidden border border-gray-200 dark:border-gray-600 bg-gray-100 dark:bg-gray-800 aspect-video">
                                        <img :src="img.dataUrl" class="w-full h-full object-cover">
                                        <button type="button"
                                            x-on:click="removeFlagImage(idx)"
                                            class="absolute top-1 right-1 hidden group-hover:flex items-center justify-center w-5 h-5 rounded-full bg-black/60 text-white text-xs leading-none hover:bg-black/80 transition-colors">
                                            ×
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                    {{-- ── end image zone ── --}}

                    <template x-if="flagError">
                        <p class="text-sm text-red-500 dark:text-red-400" x-text="flagError"></p>
                    </template>
                </div>
                {{-- Footer --}}
                <div class="flex items-center justify-between gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/60">
                    <template x-if="flagModalAnn?._flagged">
                        <div class="flex items-center gap-2">
                            <button type="button"
                                x-on:click="submitFlag(false)"
                                x-bind:disabled="flagSaving"
                                class="text-sm px-4 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors disabled:opacity-50">
                                Clear flag
                            </button>
                            <a :href="'{{ url('/documents') }}/' + document?.id + '/edit'"
                               target="_blank"
                               class="text-sm px-4 py-1.5 rounded-lg bg-green-600 text-white hover:bg-green-700 font-medium transition-colors">
                                Fix in Editor →
                            </a>
                        </div>
                    </template>
                    <template x-if="!flagModalAnn?._flagged">
                        <div></div>
                    </template>
                    <div class="flex items-center gap-2">
                        <button type="button"
                            x-on:click="flagModal = false"
                            class="text-sm px-4 py-1.5 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                            Cancel
                        </button>
                        <button type="button"
                            x-on:click="submitFlag(true)"
                            x-bind:disabled="flagSaving"
                            class="text-sm px-4 py-1.5 rounded-lg bg-green-600 text-white hover:bg-green-700 font-medium transition-colors disabled:opacity-50 flex items-center gap-1.5">
                            <template x-if="flagSaving">
                                <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4l3-3-3-3v4a8 8 0 00-8 8h4z"/>
                                </svg>
                            </template>
                            <span>⚑ Flag as mismatch</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>

    </div>{{-- /x-data pdfRecon --}}

    <style>
        [x-cloak] { display: none !important; }

        .recon-ann {
            position: absolute;
            box-sizing: border-box;
        }

        .recon-acro {
            box-shadow: inset 0 0 0 0.5px rgba(37, 99, 235, 0.25);
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
            let _acroPdfDoc  = null;
            let _exactTextWidthProbe = null;
            const _loadedWebFonts = new Set();
            let _embeddedFontsBySource = {};

            const _FONT_MAP = {
                Helvetica:     '"AnnotHelvetica","Arimo",Arial,sans-serif',
                Arial:         '"AnnotHelvetica","Arimo",Arial,sans-serif',
                ArialMT:       '"AnnotHelvetica","Arimo",Arial,sans-serif',
                ArialBoldMT:   '"AnnotHelvetica","Arimo",Arial,sans-serif',
                ArialBoldItalicMT: '"AnnotHelvetica","Arimo",Arial,sans-serif',
                FreeSans:      '"Liberation Sans","Arimo",Arial,Helvetica,sans-serif',
                FreeSerif:     '"Liberation Serif","Times New Roman",Times,serif',
                FreeMono:      '"Liberation Mono","Courier New",Courier,monospace',
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
                DejaVuSans:    '"DejaVu Sans","Liberation Sans",Arial,Helvetica,sans-serif',
                DejaVuSerif:   '"DejaVu Serif","Liberation Serif","Times New Roman",Times,serif',
                DejaVuSansMono:'"DejaVu Sans Mono","Liberation Mono","Courier New",monospace',
            };
            // ── Embedded font registry (populated per-document from the API) ──
            let _embeddedFonts = null;  // { cleanName: { family, css_weight, css_style, css_stretch, file_path, file_ext } }
            let _disabledEmbeddedFonts = new Set();
            const _allowEmbeddedReconstructionFonts = false;

            function _normalizeEmbeddedFontName(fontName) {
                let cleaned = String(fontName || '').trim().replace(/^PDF_/i, '');
                if (!cleaned) return '';
                cleaned = cleaned.replace(/PSMT$/i, '').replace(/PS(-\w+MT)$/i, '$1').trim();
                if (cleaned.includes('+')) {
                    const parts = cleaned.split('+', 2);
                    if (parts[0].length === 6) {
                        cleaned = parts[1];
                    }
                }
                if (/^[A-Z]{6}[A-Z]/.test(cleaned) && cleaned.length > 7) {
                    const withoutPrefix = cleaned.substring(6);
                    if (/^[A-Z][a-z]/.test(withoutPrefix)) {
                        cleaned = withoutPrefix;
                    }
                }
                return cleaned.replace(/[,\s]+$/g, '').trim();
            }

            // Inject @font-face rules for all embedded fonts in the document.
            // Each font is registered under 'PDF_{clean_name}' so it can be used
            // in CSS without conflicting with system fonts.
            function _shouldBypassEmbeddedFont(fontName, family = '') {
                const rawName = _normalizeEmbeddedFontName(fontName);
                const rawFamily = String(family || '').trim();
                if (!rawName && !rawFamily) return false;
                if (!_allowEmbeddedReconstructionFonts) return true;
                if (_disabledEmbeddedFonts.has(rawName.toLowerCase())) return true;

                // Some runtime-extracted GNU FreeFont subsets render malformed glyphs
                // in browsers/FreeType (e.g. "ff" paints as "ffff"). Use stable
                // fallback families for reconstruction instead of the extracted file.
                // Some extracted ArialMT subsets also ship broken browser cmaps
                // (e.g. "/" -> "F", digits missing). Prefer the stable system
                // Arial/Helvetica stack over the embedded browser font.
                return /^Free(?:Sans|Serif|Mono)/i.test(rawName)
                    || /^Free(?:Sans|Serif|Mono)/i.test(rawFamily)
                    || /^ArialMT$/i.test(rawName);
            }

            function _collectEmbeddedFontValidationSamples(embeddedFonts, annotations) {
                const sampleMap = new Map();
                if (!embeddedFonts || typeof embeddedFonts !== 'object') return sampleMap;

                const available = new Set(
                    Object.entries(embeddedFonts)
                        .map(([fontKey, fontData]) => _normalizeEmbeddedFontName(fontData?.clean_name || fontKey || ''))
                        .filter(Boolean)
                        .map((name) => name.toLowerCase())
                );

                (Array.isArray(annotations) ? annotations : []).forEach((annotation) => {
                    const spans = Array.isArray(annotation?.sourceSpans) ? annotation.sourceSpans : [];
                    spans.forEach((span) => {
                        const fontName = _normalizeEmbeddedFontName(span?.embedded_font_name || span?.font || '');
                        if (!fontName || !available.has(fontName.toLowerCase())) return;

                        const bbox = Array.isArray(span?.bbox) && span.bbox.length >= 4 ? span.bbox : null;
                        const text = String(span?.render_text ?? span?.text ?? '').replace(/\r/g, '');
                        const fontSizePx = Number(span?.font_size ?? span?.fontSize);
                        if (!bbox || !Number.isFinite(fontSizePx) || fontSizePx <= 0) return;
                        if (!text || !text.trim()) return;

                        const targetWidthPx = Number(bbox[2]) - Number(bbox[0]);
                        if (!Number.isFinite(targetWidthPx) || targetWidthPx <= 0.5) return;

                        const key = fontName.toLowerCase();
                        const samples = sampleMap.get(key) || [];
                        if (samples.some((sample) => sample.text === text && Math.abs(sample.targetWidthPx - targetWidthPx) <= 0.25)) {
                            return;
                        }

                        const visibleChars = Array.from(text.replace(/\s+/g, ''));
                        samples.push({
                            text,
                            targetWidthPx,
                            fontSizePx,
                            fontWeight: String(span?.font_weight || span?.fontWeight || (span?.bold ? '700' : '400')),
                            fontStyle: span?.fontStyle || (span?.italic ? 'italic' : 'normal'),
                            fontStretch: 'normal',
                            score: new Set(visibleChars).size,
                        });
                        samples.sort((left, right) => {
                            if (right.score !== left.score) return right.score - left.score;
                            return right.text.length - left.text.length;
                        });
                        sampleMap.set(key, samples.slice(0, 8));
                    });
                });

                return sampleMap;
            }

            function _validateEmbeddedFontsAgainstSamples(embeddedFonts, annotations) {
                if (!embeddedFonts || typeof embeddedFonts !== 'object') return;

                const samplesByFont = _collectEmbeddedFontValidationSamples(embeddedFonts, annotations);
                for (const [fontKey, fontData] of Object.entries(embeddedFonts)) {
                    const cleanName = _normalizeEmbeddedFontName(fontData?.clean_name || fontKey || '');
                    if (!cleanName) continue;
                    if (_shouldBypassEmbeddedFont(cleanName, fontData?.family || '')) {
                        _disabledEmbeddedFonts.add(cleanName.toLowerCase());
                        continue;
                    }

                    const samples = samplesByFont.get(cleanName.toLowerCase()) || [];
                    if (!samples.length) continue;

                    const embeddedFamily = `'PDF_${cleanName}'`;
                    const fallbackFamily = _fontMapFallback(fontData?.family || cleanName);
                    let passCount = 0;
                    let failCount = 0;

                    samples.forEach((sample) => {
                        const measuredEmbeddedWidth = _measureExactTextDomWidth(
                            sample.text,
                            sample.fontSizePx,
                            embeddedFamily,
                            sample.fontWeight,
                            sample.fontStyle,
                            sample.fontStretch
                        );
                        const measuredFallbackWidth = _measureExactTextDomWidth(
                            sample.text,
                            sample.fontSizePx,
                            fallbackFamily,
                            sample.fontWeight,
                            sample.fontStyle,
                            sample.fontStretch
                        );

                        if (!Number.isFinite(measuredEmbeddedWidth) || measuredEmbeddedWidth <= 0) {
                            failCount++;
                            return;
                        }

                        const targetWidth = Math.max(1, sample.targetWidthPx);
                        const embeddedError = Math.abs(measuredEmbeddedWidth - targetWidth) / targetWidth;
                        const fallbackError = Number.isFinite(measuredFallbackWidth) && measuredFallbackWidth > 0
                            ? Math.abs(measuredFallbackWidth - targetWidth) / targetWidth
                            : Number.POSITIVE_INFINITY;

                        const materiallyWorseThanFallback = fallbackError + 0.08 < embeddedError;
                        const materiallyWrong = embeddedError > 0.22;
                        if (materiallyWrong && materiallyWorseThanFallback) {
                            failCount++;
                            return;
                        }

                        passCount++;
                    });

                    if (failCount >= 2 && failCount > passCount) {
                        _disabledEmbeddedFonts.add(cleanName.toLowerCase());
                        console.warn('Bypassing malformed embedded font in reconstruction', {
                            font: cleanName,
                            fails: failCount,
                            passes: passCount,
                        });
                    }
                }
            }

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
                    if (_shouldBypassEmbeddedFont(cleanName, fontData.family || '')) continue;
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

            function _resolveEmbeddedFontsForSource(sourceKey) {
                const normalized = String(sourceKey || '').trim().toLowerCase();
                if (normalized && _embeddedFontsBySource && _embeddedFontsBySource[normalized]) {
                    return _embeddedFontsBySource[normalized];
                }
                return (_embeddedFontsBySource && (_embeddedFontsBySource.file || _embeddedFontsBySource.clean)) || null;
            }

            async function _applyEmbeddedFontsForSource(sourceKey, annotations = []) {
                const embeddedFonts = _resolveEmbeddedFontsForSource(sourceKey);
                _disabledEmbeddedFonts = new Set();
                _loadEmbeddedFontFaces(embeddedFonts);
                if ((embeddedFonts && _allowEmbeddedReconstructionFonts) || _loadedWebFonts.size > 0) {
                    try { await document.fonts.ready; } catch (_) {}
                }
                if (embeddedFonts && _allowEmbeddedReconstructionFonts) {
                    _validateEmbeddedFontsAgainstSamples(embeddedFonts, annotations);
                }
            }

            function _resolveCssFont(sourceName, family) {
                const normalizedSource = _normalizePdfFontFamily(sourceName);
                const normalizedFamily = _normalizePdfFontFamily(family);

                // Normalize PostScript suffixes: TimesNewRomanPSMT → TimesNewRoman.
                // PDF fonts with PSMT / PS-<Variant>MT suffixes are identical to
                // the base font and must resolve to the same embedded font entry.
                // 1. Try embedded font by exact source name (fontSourceName)
                const rawExact = _normalizeEmbeddedFontName(sourceName || family || '');
                if (rawExact && _embeddedFonts) {
                    for (const [fontKey, fontData] of Object.entries(_embeddedFonts)) {
                        const cleanName = _normalizeEmbeddedFontName(fontData.clean_name || fontKey || '');
                        const embeddedFamily = _normalizePdfFontFamily(fontData.family || fontKey || '');
                        if (_shouldBypassEmbeddedFont(cleanName, embeddedFamily)) {
                            continue;
                        }
                        if (cleanName.toLowerCase() === rawExact.toLowerCase()) {
                            const fallback = _fontMapFallback(embeddedFamily || rawExact);
                            return `'PDF_${cleanName}', ${fallback}`;
                        }
                    }
                    // Try family-level match
                    const rawFamily = normalizedFamily || _normalizePdfFontFamily(rawExact);
                    if (rawFamily) {
                        for (const [fontKey, fontData] of Object.entries(_embeddedFonts)) {
                            const embFamily = _normalizePdfFontFamily(fontData.family || fontKey || '');
                            const cleanName = _normalizeEmbeddedFontName(fontData.clean_name || fontKey || '');
                            if (_shouldBypassEmbeddedFont(cleanName, embFamily)) {
                                continue;
                            }
                            if (embFamily.toLowerCase() === rawFamily.toLowerCase()) {
                                const fallback = _fontMapFallback(embFamily);
                                return `'PDF_${cleanName}', ${fallback}`;
                            }
                        }
                    }
                }
                // 2. Fall back to static font map
                return _fontMapFallback(normalizedSource || normalizedFamily || rawExact || '');
            }

            function _fontMapFallback(name) {
                if (!name) return _FONT_MAP.Helvetica;
                const normalized = _normalizePdfFontFamily(name);
                const k = String(normalized || name).replace(/['"]/g, '').trim()
                    .replace(/[-_ ]?(regular|bold|italic|oblique|light|medium|condensed|narrow|unicode)$/i, '');
                for (const [key, val] of Object.entries(_FONT_MAP)) {
                    if (key.toLowerCase() === k.toLowerCase()) return val;
                }
                if (_shouldPreferWebFontFamily(normalized)) {
                    _ensureWebFontFamilyLoaded(normalized);
                    return `"${normalized}", ${_FONT_MAP.Helvetica}`;
                }
                return _FONT_MAP.Helvetica;
            }

            function _normalizePdfFontFamily(fontName) {
                if (!fontName) return '';
                let cleaned = String(fontName).replace(/['"]/g, '').trim();

                if (cleaned.includes('+')) {
                    const parts = cleaned.split('+', 2);
                    if (parts[0].length === 6) {
                        cleaned = parts[1];
                    }
                }

                if (/^[A-Z]{6}[A-Z]/.test(cleaned) && cleaned.length > 7) {
                    const withoutPrefix = cleaned.substring(6);
                    if (/^[A-Z][a-z]/.test(withoutPrefix)) {
                        cleaned = withoutPrefix;
                    }
                }

                const basePart = cleaned.split(/[-_,]/)[0] || cleaned;
                const weightSuffixes = [
                    'Thin', 'Hairline', 'ExtraLight', 'UltraLight', 'Light',
                    'Regular', 'Medium', 'SemiBold', 'DemiBold', 'Bold',
                    'ExtraBold', 'UltraBold', 'Black', 'Heavy',
                ];
                let family = basePart;
                for (const suffix of weightSuffixes) {
                    if (family.endsWith(suffix) && family.length > suffix.length) {
                        family = family.substring(0, family.length - suffix.length);
                        break;
                    }
                }

                return family.replace(/[,\s]+$/g, '').trim();
            }

            function _shouldPreferWebFontFamily(fontName) {
                const normalized = _normalizePdfFontFamily(fontName);
                if (!normalized) return false;
                return new Set([
                    'Roboto',
                    'OpenSans',
                    'Lato',
                    'Montserrat',
                    'Poppins',
                    'Raleway',
                    'Nunito',
                    'Inter',
                    'Oswald',
                    'SourceSansPro',
                    'PlayfairDisplay',
                    'Merriweather',
                ]).has(normalized);
            }

            function _ensureWebFontFamilyLoaded(fontName) {
                const family = _normalizePdfFontFamily(fontName);
                if (!family || _loadedWebFonts.has(family)) return;
                _loadedWebFonts.add(family);
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = `https://fonts.googleapis.com/css2?family=${encodeURIComponent(family)}:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap`;
                document.head.appendChild(link);
            }

            function _preloadAnnotationFontFamilies(annotations) {
                (Array.isArray(annotations) ? annotations : []).forEach((annotation) => {
                    [
                        annotation?.fontFamily,
                        annotation?.fontSourceName,
                    ].forEach((fontName) => {
                        if (_shouldPreferWebFontFamily(fontName)) {
                            _ensureWebFontFamilyLoaded(fontName);
                        }
                    });
                    (Array.isArray(annotation?.sourceSpans) ? annotation.sourceSpans : []).forEach((span) => {
                        [
                            span?.embedded_font_name,
                            span?.embedded_font_family,
                            span?.font,
                            span?.fontFamily,
                        ].forEach((fontName) => {
                            if (_shouldPreferWebFontFamily(fontName)) {
                                _ensureWebFontFamilyLoaded(fontName);
                            }
                        });
                    });
                });
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

            function _measureExactTextDomWidth(
                text,
                fontSizePx,
                fontFamily,
                fontWeight,
                fontStyle,
                fontStretch = '',
                letterSpacing = '',
                wordSpacing = ''
            ) {
                const probe = _ensureExactTextWidthProbe();
                if (!(probe instanceof HTMLElement)) return 0;
                probe.textContent = String(text || '');
                probe.style.fontFamily = fontFamily || '';
                probe.style.fontSize = `${Math.max(0, Number(fontSizePx) || 0)}px`;
                probe.style.fontWeight = fontWeight || '400';
                probe.style.fontStyle = fontStyle || 'normal';
                probe.style.fontStretch = String(fontStretch || '').trim() || 'normal';
                probe.style.letterSpacing = String(letterSpacing || '').trim();
                probe.style.wordSpacing = String(wordSpacing || '').trim();
                const rect = probe.getBoundingClientRect();
                return rect.width || 0;
            }

            function _applyPdfTextCss(element) {
                if (!(element instanceof HTMLElement)) return;
                element.style.fontKerning = 'none';
                element.style.fontVariantLigatures = 'none';
                element.style.fontFeatureSettings = '"kern" 0, "liga" 0, "clig" 0, "calt" 0';
                element.style.fontSynthesis = 'none';
                element.style.textRendering = 'geometricPrecision';
                element.style.setProperty('-webkit-font-smoothing', 'antialiased');
                element.style.setProperty('-moz-osx-font-smoothing', 'grayscale');
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
                element.style.letterSpacing = '';
                element.style.wordSpacing = '';
                element.style.transform = '';
                element.style.transformOrigin = '';
                if (targetWidth <= 0 || effectiveFontSize <= 0 || !sampleText) {
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
                    return false;
                }

                const rawRatio = targetWidth / measuredWidth;
                if (!Number.isFinite(rawRatio) || rawRatio <= 0) {
                    return false;
                }

                const widthDelta = targetWidth - measuredWidth;
                if (Math.abs(widthDelta) <= 0.25) {
                    return false;
                }

                const glyphCount = Array.from(sampleText).filter((ch) => ch !== '\n' && ch !== '\r').length;
                const wordGapCount = (sampleText.match(/ /g) || []).length;
                let appliedWordSpacingPx = 0;
                let measuredWithSpacing = measuredWidth;

                // PDF lines that look "too short" are usually underfilling at word gaps,
                // not because each glyph needs horizontal scaling.
                if (wordGapCount > 0) {
                    const idealWordSpacingPx = widthDelta / wordGapCount;
                    const clampedWordSpacingPx = Math.max(-0.75, Math.min(1.5, idealWordSpacingPx));
                    if (Math.abs(clampedWordSpacingPx) >= 0.01) {
                        const nextMeasuredWidth = _measureExactTextDomWidth(
                            sampleText,
                            effectiveFontSize,
                            fontFamily,
                            fontWeight,
                            fontStyle,
                            fontStretch,
                            '',
                            `${clampedWordSpacingPx}px`
                        );
                        if (Number.isFinite(nextMeasuredWidth) && nextMeasuredWidth > 0) {
                            appliedWordSpacingPx = clampedWordSpacingPx;
                            measuredWithSpacing = nextMeasuredWidth;
                        }
                    }
                }

                const remainingDelta = targetWidth - measuredWithSpacing;
                if (glyphCount > 1) {
                    const idealLetterSpacingPx = remainingDelta / Math.max(1, glyphCount - 1);
                    const clampedLetterSpacingPx = Math.max(-0.45, Math.min(0.65, idealLetterSpacingPx));
                    if (Math.abs(clampedLetterSpacingPx) >= 0.01) {
                        const nextMeasuredWidth = _measureExactTextDomWidth(
                            sampleText,
                            effectiveFontSize,
                            fontFamily,
                            fontWeight,
                            fontStyle,
                            fontStretch,
                            `${clampedLetterSpacingPx}px`,
                            appliedWordSpacingPx ? `${appliedWordSpacingPx}px` : ''
                        );
                        if (Number.isFinite(nextMeasuredWidth) && nextMeasuredWidth > 0) {
                            element.style.wordSpacing = appliedWordSpacingPx ? `${appliedWordSpacingPx.toFixed(3)}px` : '';
                            element.style.letterSpacing = `${clampedLetterSpacingPx.toFixed(3)}px`;
                            if (Math.abs(targetWidth - nextMeasuredWidth) <= 0.75 || rawRatio >= 1) {
                                return true;
                            }
                        }
                    }
                }

                if (appliedWordSpacingPx) {
                    element.style.wordSpacing = `${appliedWordSpacingPx.toFixed(3)}px`;
                    if (Math.abs(remainingDelta) <= 0.75 || rawRatio >= 1) {
                        return true;
                    }
                }

                // Prefer spacing adjustments for widening. Only use scaleX as a
                // last resort when shrinking overflow.
                if (rawRatio >= 1) {
                    return appliedWordSpacingPx !== 0;
                }

                const clampedRatio = Math.max(minRatio, Math.min(maxRatio, rawRatio));
                if (Math.abs(clampedRatio - 1) <= 0.03) {
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
                acroFormEntries: [],
                acroFieldLookup: {},
                acroWidgetsByPage: {},
                drawAcro: false,
                acroLoading: false,
                acroError: null,
                renderLoading: false,
                /* ── split-view comparison ── */
                splitView:         false,
                splitLoading:      false,
                splitError:        null,
                splitAnn:          null,   // annotation currently being compared
                origOpacity:       50,     // 0–100 slider value
                origVisible:       true,   // toggle original layer on/off

                /* ── zoom ── */
                zoomLevel: 1.5,

                /* ── pan ── */
                panMode: false,
                _panDragging: false,
                _panStart: {x: 0, y: 0},
                _panScroll: {l: 0, t: 0},

                /* ── filter ── */
                filterCurrentPage: false,
                filterFlagged: false,

                /* ── annotation debug modal ── */
                debugModal: false,
                debugModalAnn: null,
                debugData: null,
                debugLoading: false,
                debugError: null,

                /* ── flag modal ── */
                flagModal: false,
                flagModalAnn: null,
                flagReason: '',
                flagImages: [],   // [{dataUrl, name}] — pending (not yet saved)
                flagSaving: false,
                flagError: null,

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
                    let list = this.filterCurrentPage ? this.pageAnnotations : this.annotations;
                    if (this.filterFlagged) list = list.filter(a => a._flagged);
                    return list;
                },

                get drawnCount() {
                    return this.pageAnnotations.filter((a) => this.isDrawn(a)).length;
                },

                get pageAcroWidgets() {
                    return this.acroWidgetsByPage[String(this.currentPage)] || [];
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

                normalizePromotedComparableText(value) {
                    return String(value || '')
                        .replace(/\u00A0/g, ' ')
                        .replace(/\s+/g, ' ')
                        .trim()
                        .toLowerCase();
                },

                promotedAnnotationIsSyntheticMerge(annotation) {
                    if (!annotation?.promotedFromExtraction) return false;
                    return String(annotation?.id || '').includes('_merge_')
                        || String(annotation?.promotedSourceKey || '').includes('__merge__');
                },

                promotedSavedAnnotationHasMaterialEdits(annotation) {
                    if (!annotation?.promotedFromExtraction) return false;
                    if (annotation.promotedDirty || annotation.promotedReflowEnabled) return true;
                    return this.normalizePromotedComparableText(annotation.text || '')
                        !== this.normalizePromotedComparableText(annotation.originalText || '');
                },

                shouldDiscardLegacySyntheticMergedPromotedAnnotation(annotation) {
                    return this.promotedAnnotationIsSyntheticMerge(annotation)
                        && !this.promotedSavedAnnotationHasMaterialEdits(annotation);
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
                    _acroPdfDoc       = null;
                    _origPdfDoc       = null;
                    this.splitView    = false;
                    this.pdfLoaded    = false;
                    this.activeSrc    = 'clean';
                    this.annotations  = [];
                    this.drawnIds     = {};
                    _embeddedFontsBySource = {};
                    this.acroFormEntries = [];
                    this.acroFieldLookup = {};
                    this.acroWidgetsByPage = {};
                    this.drawAcro = false;
                    this.acroLoading = false;
                    this.acroError = null;
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
                        this.annotations = (data.annotations || [])
                            .filter((a) => !this.shouldDiscardLegacySyntheticMergedPromotedAnnotation(a))
                            .map((a, i) => ({
                                ...a,
                                _uid:        String(a.id || a.db_id || '') + '_' + i,
                                _flagged:    !!(a.db_flagged),
                                _flagReason: a.db_flag_reason || '',
                                _flagImages: Array.isArray(a.db_flag_images) ? a.db_flag_images : [],
                            }));
                        this.acroFormEntries = Array.isArray(data.acro_form_entries) ? data.acro_form_entries : [];
                        this.acroFieldLookup = this.buildAcroFieldLookup(this.acroFormEntries);

                        _preloadAnnotationFontFamilies(this.annotations);
                        _embeddedFontsBySource = (data.embedded_fonts_by_source && typeof data.embedded_fonts_by_source === 'object')
                            ? data.embedded_fonts_by_source
                            : {
                                file: data.embedded_fonts || null,
                                clean: data.embedded_fonts || null,
                            };

                        // Load the font set for the PDF source we are about to render.
                        await _applyEmbeddedFontsForSource(this.activeSrc, this.annotations);

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
                        await _applyEmbeddedFontsForSource(key, this.annotations);
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
                    if (this.drawAcro) {
                        await this.ensureAcroWidgetsForPage(this.currentPage);
                    }
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
                    canvas.width  = Math.round(viewport.width);
                    canvas.height = Math.round(viewport.height);
                    _origRenderTask = page.render({ canvasContext: canvas.getContext('2d'), viewport });
                    await _origRenderTask.promise.catch(() => {});
                    _origRenderTask = null;
                },

                async toggleOriginalOverlay() {
                    if (!this.document) return;
                    if (this.splitView) { this.splitView = false; return; }
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

                async toggleDrawAcro() {
                    this.drawAcro = !this.drawAcro;
                    this.acroError = null;
                    if (this.drawAcro) {
                        await this.ensureAcroWidgetsForPage(this.currentPage);
                    }
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
                    // When pdfWidth==0 but pdfHeight>0 (unbounded-width promoted annotation),
                    // synthesise the width from the union x-extents of all sourceLineBBoxes so
                    // the multi-line rendering path has a valid box to work with.
                    if (pw === 0 && ph > 0 && Number.isFinite(px) && Number.isFinite(py)
                            && Array.isArray(ann.sourceLineBBoxes) && ann.sourceLineBBoxes.length > 0) {
                        const bbs = ann.sourceLineBBoxes.filter(b => Array.isArray(b) && b.length >= 4);
                        if (bbs.length > 0) {
                            const synW = Math.max(...bbs.map(b => Number(b[2]))) - Math.min(...bbs.map(b => Number(b[0])));
                            if (synW > 0) return { x: px, y: py, w: synW, h: ph };
                        }
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
                    if (this.drawAcro) {
                        this.pageAcroWidgets.forEach((widget) => {
                            this.drawAcroWidgetElement(widget, overlayEl, scale);
                        });
                    }
                },

                buildAcroFieldLookup(entries) {
                    const lookup = {};
                    (Array.isArray(entries) ? entries : []).forEach((entry) => {
                        const keys = [
                            String(entry?.key || '').trim(),
                            String(entry?.fieldName || '').trim(),
                        ].filter(Boolean);
                        keys.forEach((key) => {
                            lookup[key] = entry;
                        });
                    });
                    return lookup;
                },

                normalizeAcroRect(rectLike) {
                    if (!Array.isArray(rectLike) || rectLike.length < 4) return null;
                    const rect = rectLike.slice(0, 4).map((value) => Number(value));
                    return rect.every((value) => Number.isFinite(value)) ? rect : null;
                },

                normalizeAcroTextColor(colorLike) {
                    if (typeof colorLike === 'string') {
                        const value = colorLike.trim();
                        if (!value) return null;
                        if (/^#[0-9a-f]{6}$/i.test(value)) return value.toLowerCase();
                        if (/^[0-9a-f]{6}$/i.test(value)) return `#${value.toLowerCase()}`;
                        return null;
                    }

                    if (Array.isArray(colorLike) && colorLike.length > 0) {
                        const values = colorLike.slice(0, 3).map((value) => Number(value));
                        if (!values.every((value) => Number.isFinite(value))) return null;
                        const rgb = values.map((value) => (
                            value <= 1
                                ? Math.max(0, Math.min(255, Math.round(value * 255)))
                                : Math.max(0, Math.min(255, Math.round(value)))
                        ));
                        while (rgb.length < 3) rgb.push(rgb[0]);
                        return `#${rgb.map((value) => value.toString(16).padStart(2, '0')).join('')}`;
                    }

                    return null;
                },

                acroFieldKey(annotation) {
                    return String(annotation?.fieldName || annotation?.id || annotation?.fullName || '').trim();
                },

                async ensureAcroPdfLoaded() {
                    if (_acroPdfDoc || !this.document?.file_url) return;
                    _acroPdfDoc = await pdfjsLib.getDocument(this.document.file_url).promise;
                },

                normalizeAcroWidget(annotation, pageNumber) {
                    const fieldKey = this.acroFieldKey(annotation);
                    const dbEntry = this.acroFieldLookup[fieldKey] || null;
                    const rect = this.normalizeAcroRect(dbEntry?.rect || annotation?.rect);
                    if (!rect) return null;

                    const [x0, y0, x1, y1] = rect;
                    const left = Math.min(x0, x1);
                    const right = Math.max(x0, x1);
                    const bottom = Math.min(y0, y1);
                    const top = Math.max(y0, y1);

                    return {
                        key: fieldKey || `acro-${pageNumber}-${Math.random().toString(36).slice(2)}`,
                        pageIndex: pageNumber - 1,
                        fieldName: String(dbEntry?.fieldName || annotation?.fieldName || fieldKey || '').trim(),
                        fieldType: String(dbEntry?.fieldType || annotation?.fieldType || '').trim().toUpperCase(),
                        value: dbEntry?.value ?? annotation?.fieldValue ?? '',
                        exportValue: String(dbEntry?.exportValue || annotation?.exportValue || '').trim(),
                        checkBox: Boolean(dbEntry?.checkBox ?? annotation?.checkBox),
                        radioButton: Boolean(dbEntry?.radioButton ?? annotation?.radioButton),
                        combo: Boolean(dbEntry?.combo ?? annotation?.combo),
                        multiLine: Boolean(dbEntry?.multiLine ?? annotation?.multiLine),
                        multiSelect: Boolean(dbEntry?.multiSelect ?? annotation?.multiSelect),
                        readOnly: Boolean(annotation?.readOnly),
                        textColor: this.normalizeAcroTextColor(
                            dbEntry?.textColor
                            ?? annotation?.textColor
                            ?? annotation?.fontColor
                            ?? annotation?.color
                            ?? annotation?.defaultAppearanceData?.fontColor
                        ) || '#0f172a',
                        rect: [left, bottom, right, top],
                    };
                },

                async ensureAcroWidgetsForPage(pageNumber) {
                    const pageKey = String(pageNumber);
                    if (Array.isArray(this.acroWidgetsByPage[pageKey])) {
                        return this.acroWidgetsByPage[pageKey];
                    }
                    if (!this.document) return [];

                    this.acroLoading = true;
                    this.acroError = null;

                    try {
                        await this.ensureAcroPdfLoaded();
                        if (!_acroPdfDoc) return [];

                        const page = await _acroPdfDoc.getPage(pageNumber);
                        const widgets = await page.getAnnotations({ intent: 'display' });
                        const normalized = (Array.isArray(widgets) ? widgets : [])
                            .filter((annotation) => (
                                annotation
                                && annotation.subtype === 'Widget'
                                && !annotation.hidden
                            ))
                            .map((annotation) => this.normalizeAcroWidget(annotation, pageNumber))
                            .filter(Boolean);

                        this.acroWidgetsByPage = {
                            ...this.acroWidgetsByPage,
                            [pageKey]: normalized,
                        };

                        return normalized;
                    } catch (error) {
                        this.acroError = error?.message || String(error);
                        return [];
                    } finally {
                        this.acroLoading = false;
                    }
                },

                drawAcroWidgetElement(widget, overlayEl, scale) {
                    if (!widget || !Array.isArray(widget.rect) || widget.rect.length < 4) return;

                    const [leftPdf, bottomPdf, rightPdf, topPdf] = widget.rect;
                    const cssLeft = leftPdf * scale;
                    const cssTop = this.canvasHeight - (topPdf * scale);
                    const cssWidth = Math.max(3, (rightPdf - leftPdf) * scale);
                    const cssHeight = Math.max(3, (topPdf - bottomPdf) * scale);
                    const fieldType = String(widget.fieldType || '').toUpperCase();

                    const el = document.createElement('div');
                    el.className = 'recon-ann recon-acro';
                    el.style.left = `${cssLeft.toFixed(2)}px`;
                    el.style.top = `${cssTop.toFixed(2)}px`;
                    el.style.width = `${cssWidth.toFixed(2)}px`;
                    el.style.height = `${cssHeight.toFixed(2)}px`;
                    el.style.border = '1.5px solid #2563eb';
                    el.style.background = 'rgba(37,99,235,0.08)';
                    el.style.borderRadius = '2px';
                    el.style.boxSizing = 'border-box';
                    el.title = widget.fieldName || widget.key || 'AcroForm field';

                    if (fieldType === 'BTN' && (widget.checkBox || widget.radioButton)) {
                        const mark = document.createElement('div');
                        mark.style.position = 'absolute';
                        mark.style.inset = '0';
                        mark.style.display = 'flex';
                        mark.style.alignItems = 'center';
                        mark.style.justifyContent = 'center';
                        mark.style.color = '#2563eb';
                        mark.style.fontSize = `${Math.max(10, Math.min(cssHeight, cssWidth) * 0.7).toFixed(1)}px`;
                        mark.style.fontWeight = '700';
                        mark.textContent = widget.radioButton
                            ? ((String(widget.value || '') !== '' && String(widget.value || '') === String(widget.exportValue || '')) ? '●' : '')
                            : (widget.value ? '✓' : '');
                        el.appendChild(mark);
                    } else {
                        const label = document.createElement('div');
                        label.style.position = 'absolute';
                        label.style.left = '0';
                        label.style.right = '0';
                        label.style.top = '0';
                        label.style.bottom = '0';
                        label.style.display = 'flex';
                        label.style.alignItems = 'center';
                        label.style.padding = '0 4px';
                        label.style.overflow = 'hidden';
                        label.style.whiteSpace = 'nowrap';
                        label.style.textOverflow = 'ellipsis';
                        label.style.fontFamily = 'ui-monospace, SFMono-Regular, Menlo, monospace';
                        label.style.fontSize = `${Math.max(8, Math.min(11, cssHeight * 0.45)).toFixed(1)}px`;
                        label.style.color = widget.textColor || '#0f172a';
                        label.textContent = String(widget.value ?? '').trim() || `[${fieldType || 'ACRO'}] ${widget.fieldName || widget.key || ''}`.trim();
                        el.appendChild(label);
                    }

                    overlayEl.appendChild(el);
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
                        const fontStyle     = ann.fontStyle  || 'normal';

                        // Prefer the first sourceSpan's embedded_font_name for resolution —
                        // annotation.fontSourceName is sometimes truncated (e.g. "ITCFranklinGothicStd-Dem"
                        // vs the full "ITCFranklinGothicStd-Demi" in the span).
                        const _isExtractedLineFragment = Boolean(ann.promotedFromExtraction)
                            && /-lines-\d+-\d+$/.test(String(ann.promotedSourceKey || '').trim())
                            && Array.isArray(ann.sourceLineBBoxes)
                            && ann.sourceLineBBoxes.length <= 1;
                        const _srcLineBBoxesRaw = Array.isArray(ann.sourceLineBBoxes) ? ann.sourceLineBBoxes : [];
                        const _srcLineBBoxes = _srcLineBBoxesRaw
                            .filter((bbox) => Array.isArray(bbox) && bbox.length >= 4)
                            .map((bbox) => bbox.slice(0, 4).map((value) => Number(value)));
                        const _srcSpansRaw = (_isExtractedLineFragment ? [] : (Array.isArray(ann.sourceSpans) ? ann.sourceSpans : [])).filter((span) => {
                            if (!ann.promotedFromExtraction || !_srcLineBBoxes.length) return true;
                            const spanBBox = Array.isArray(span?.bbox) && span.bbox.length >= 4
                                ? span.bbox.slice(0, 4).map((value) => Number(value))
                                : null;
                            if (!spanBBox || spanBBox.some((value) => !Number.isFinite(value))) return false;
                            return _srcLineBBoxes.some((lineBBox) => {
                                if (lineBBox.some((value) => !Number.isFinite(value))) return false;
                                const xi = Math.max(spanBBox[0], lineBBox[0] - 0.25);
                                const yi = Math.max(spanBBox[1], lineBBox[1] - 0.25);
                                const xa = Math.min(spanBBox[2], lineBBox[2] + 0.25);
                                const ya = Math.min(spanBBox[3], lineBBox[3] + 0.25);
                                return (xa - xi) > 0 && (ya - yi) > 0;
                            });
                        });
                        const _primarySpanSrcName = _srcSpansRaw.length > 0
                            ? String(_srcSpansRaw[0].embedded_font_name || _srcSpansRaw[0].font || '').trim()
                            : '';
                        const fontFamily = _resolveCssFont(
                            _primarySpanSrcName || ann.fontSourceName,
                            ann.fontFamily
                        );

                        // Resolve font-weight from embedded font metadata when available —
                        // the embedded font css_weight uses full name-pattern analysis
                        // (catches Demi→600, BdIt/BdOu→700 etc.) which is more accurate
                        // than the stored ann.fontWeight from an older extraction.
                        const _resolveAnnFontWeight = (srcName) => {
                            if (!_embeddedFonts || !srcName) return null;
                            const raw = String(srcName).trim().toLowerCase();
                            for (const [k, fd] of Object.entries(_embeddedFonts)) {
                                if (String(fd.clean_name || k).trim().toLowerCase() === raw) {
                                    return fd.css_weight || null;
                                }
                            }
                            return null;
                        };
                        const fontWeight = _resolveAnnFontWeight(_primarySpanSrcName || ann.fontSourceName)
                            || ann.fontWeight || 'normal';

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
                        const getSpanPreferredTopPts = (span, fallbackFontSize = fontSize) => {
                            const bbox = getSpanBBox(span);
                            const bboxTop = bbox ? Number(bbox[1]) : null;
                            const bboxHeight = bbox ? Math.max(0, Number(bbox[3]) - Number(bbox[1])) : 0;
                            const size = Number(span?.font_size ?? span?.fontSize) || fallbackFontSize;
                            const origin = Array.isArray(span?.origin) && span.origin.length >= 2
                                ? Number(span.origin[1])
                                : null;
                            const asc = Number(span?.ascender);
                            const desc = Number(span?.descender);

                            const typoTop = (
                                Number.isFinite(origin) && Number.isFinite(asc) && size > 0
                            )
                                ? (origin - asc * size)
                                : null;

                            if (!Number.isFinite(bboxTop)) {
                                return Number.isFinite(typoTop) ? typoTop : 0;
                            }
                            if (!Number.isFinite(typoTop)) {
                                return bboxTop;
                            }

                            const expectedTypoHeight = (
                                Number.isFinite(asc) && Number.isFinite(desc) && size > 0
                            )
                                ? (asc + Math.abs(desc)) * size
                                : null;

                            // Tight MuPDF glyph bboxes often sit several points below the
                            // typographic top. In those cases, using bbox[1] matches the
                            // visible painted text better than origin - ascender * size.
                            if (
                                Number.isFinite(expectedTypoHeight)
                                && expectedTypoHeight > 0
                                && bboxHeight > 0
                                && (bboxHeight / expectedTypoHeight) < 0.82
                            ) {
                                return bboxTop;
                            }

                            if (Math.abs(typoTop - bboxTop) > Math.max(1.25, size * 0.18)) {
                                return bboxTop;
                            }

                            return typoTop;
                        };
                        const getSpanTopOffsetPx = (span, referenceTopPts) => {
                            const refTop = Number(referenceTopPts);
                            if (!Number.isFinite(refTop)) return 0;
                            const top = getSpanPreferredTopPts(span);
                            if (!Number.isFinite(top)) return 0;
                            return (top - refTop) * scale;
                        };
                        const getSourceSpanDisplayText = (span) => {
                            if (span && span.render_text !== undefined && span.render_text !== null) {
                                return String(span.render_text);
                            }
                            return String(span?.text ?? span?.rawText ?? '');
                        };
                        const getSpanLineOverlapArea = (span, lineBBox, tolerancePts = 0) => {
                            const bbox = getSpanBBox(span);
                            if (!bbox || !Array.isArray(lineBBox) || lineBBox.length < 4) return 0;
                            const xi = Math.max(Number(bbox[0]), Number(lineBBox[0]) - tolerancePts);
                            const yi = Math.max(Number(bbox[1]), Number(lineBBox[1]) - tolerancePts);
                            const xa = Math.min(Number(bbox[2]), Number(lineBBox[2]) + tolerancePts);
                            const ya = Math.min(Number(bbox[3]), Number(lineBBox[3]) + tolerancePts);
                            return Math.max(0, xa - xi) * Math.max(0, ya - yi);
                        };
                        const spanOverlapsLineBBox = (span, lineBBox, tolerancePts = 0) => (
                            getSpanLineOverlapArea(span, lineBBox, tolerancePts) > 0
                        );
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
                                candidates = candidates.filter((span) => {
                                    return spanOverlapsLineBBox(span, lineBBox, 1);
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
                            lineEl.style.color       = ann.textColor || '#000000';
                            lineEl.style.background  = 'transparent';
                            lineEl.style.padding     = '0';
                            lineEl.style.margin      = '0';
                            lineEl.style.whiteSpace  = 'pre';
                            lineEl.style.overflow    = 'visible';
                            lineEl.style.lineHeight  = lineHeightPx.toFixed(2) + 'px';
                            _applyPdfTextCss(lineEl);
                        };

                        // ── Positioning principle ──
                        // CSS places baseline at: el.style.top + fontBoundingBoxAscent
                        // where fontBoundingBoxAscent ≈ font.ascender × font_size for
                        // correctly-loaded embedded fonts.
                        // Therefore el.style.top should be: origin_y − ascender × size
                        // (the typographic top of the line), so that:
                        //   top + fontBoundingBoxAscent = origin_y − asc×size + asc×size = origin_y  ✓
                        //
                        // Legacy annotations were extracted with MuPDF producing tight glyph
                        // bboxes (bbox top ≈ origin_y − glyph_ascent, where glyph_ascent < asc).
                        // Current MuPDF (≥1.24) produces typographic bboxes (bbox top =
                        // origin_y − asc×size exactly).
                        // Both cases are handled by recomputing el.style.top from span origin:
                        //   typographic_top = origin_y − span.ascender × span.font_size
                        // which is computed above for single-line and for rect_y0_css (multi-line).

                        // For multi-line promoted annotations, Python uses sourceLineBBoxes to
                        // place each line at its exact extracted position — NOT uniform lineHeight.
                        // The translation Python applies: translate_y = rect.y0 - min(bboxes[i][1])
                        // simplifies to: line_i top = rect.y0_css + (bbox[i][1] - bbox[0][1]) * scale
                        let srcLines  = Array.isArray(ann.sourceTextLines)  ? ann.sourceTextLines  : null;
                        const srcBBoxesRaw = Array.isArray(ann.sourceLineBBoxes) ? ann.sourceLineBBoxes : null;
                        let srcBBoxes = (() => {
                            if (!srcBBoxesRaw) return null;
                            if (!srcLines || srcBBoxesRaw.length === srcLines.length) return srcBBoxesRaw;
                            const filtered = srcBBoxesRaw.filter((bbox) => (
                                Array.isArray(bbox)
                                && bbox.length >= 4
                                && (Number(bbox[2]) - Number(bbox[0])) > 1
                            ));
                            return filtered.length === srcLines.length ? filtered : srcBBoxesRaw;
                        })();

                        const synthesizeVisualLinesFromSpans = () => {
                            const positionedSpans = _srcSpansRaw
                                .filter((span) => getSpanBBox(span))
                                .slice()
                                .sort((leftSpan, rightSpan) => {
                                    const leftBox = getSpanBBox(leftSpan) || [0, 0, 0, 0];
                                    const rightBox = getSpanBBox(rightSpan) || [0, 0, 0, 0];
                                    const topDelta = Number(leftBox[1]) - Number(rightBox[1]);
                                    if (Math.abs(topDelta) > 1) {
                                        return topDelta;
                                    }
                                    return Number(leftBox[0]) - Number(rightBox[0]);
                                });

                            if (positionedSpans.length < 2) {
                                return null;
                            }

                            const groups = [];
                            positionedSpans.forEach((span) => {
                                const bbox = getSpanBBox(span);
                                if (!bbox) return;

                                const top = Number(bbox[1]) || 0;
                                const bottom = Number(bbox[3]) || top;
                                const height = Math.max(1, bottom - top);
                                const centerY = top + (height / 2);
                                const currentGroup = groups[groups.length - 1] || null;

                                if (!currentGroup) {
                                    groups.push({
                                        spans: [span],
                                        bbox: bbox.map((value) => Number(value) || 0),
                                        centerY,
                                    });
                                    return;
                                }

                                const groupBox = currentGroup.bbox;
                                const groupTop = Number(groupBox[1]) || 0;
                                const groupBottom = Number(groupBox[3]) || groupTop;
                                const groupHeight = Math.max(1, groupBottom - groupTop);
                                const groupCenterY = currentGroup.centerY;
                                const verticalOverlap = Math.max(0, Math.min(bottom, groupBottom) - Math.max(top, groupTop));
                                const sameVisualBand = verticalOverlap >= Math.min(height, groupHeight) * 0.45
                                    || Math.abs(centerY - groupCenterY) <= Math.max(1.5, Math.min(height, groupHeight) * 0.45);

                                if (sameVisualBand) {
                                    currentGroup.spans.push(span);
                                    currentGroup.bbox = [
                                        Math.min(Number(groupBox[0]) || 0, Number(bbox[0]) || 0),
                                        Math.min(groupTop, top),
                                        Math.max(Number(groupBox[2]) || 0, Number(bbox[2]) || 0),
                                        Math.max(groupBottom, bottom),
                                    ];
                                    currentGroup.centerY = ((Number(currentGroup.bbox[1]) || 0) + (Number(currentGroup.bbox[3]) || 0)) / 2;
                                    return;
                                }

                                groups.push({
                                    spans: [span],
                                    bbox: bbox.map((value) => Number(value) || 0),
                                    centerY,
                                });
                            });

                            if (groups.length <= 1) {
                                return null;
                            }

                            const synthesizedLineBBoxes = groups.map((group) => group.bbox);
                            const synthesizedLines = groups.map((group) => {
                                const lineSpans = group.spans.slice().sort((leftSpan, rightSpan) => {
                                    const leftOriginX = Array.isArray(leftSpan?.origin) ? Number(leftSpan.origin[0]) || 0 : (Number(getSpanBBox(leftSpan)?.[0]) || 0);
                                    const rightOriginX = Array.isArray(rightSpan?.origin) ? Number(rightSpan.origin[0]) || 0 : (Number(getSpanBBox(rightSpan)?.[0]) || 0);
                                    return leftOriginX - rightOriginX;
                                });
                                return lineSpans.map((span) => getSourceSpanDisplayText(span)).join('');
                            });

                            return synthesizedLines.length === synthesizedLineBBoxes.length
                                ? {
                                    lines: synthesizedLines,
                                    boxes: synthesizedLineBBoxes,
                                }
                                : null;
                        };

                        if (
                            (!srcLines || !srcBBoxes || srcBBoxes.length !== srcLines.length || srcBBoxes.length <= 1)
                            && _srcSpansRaw.length > 1
                        ) {
                            const synthesizedVisualLines = synthesizeVisualLinesFromSpans();
                            if (synthesizedVisualLines) {
                                srcLines = synthesizedVisualLines.lines;
                                srcBBoxes = synthesizedVisualLines.boxes;
                            }
                        }

                        // Any annotation with extracted per-line text + bboxes should be
                        // reconstructed line-by-line. Falling back to a single wrapped DOM
                        // block lets the browser choose line breaks and spacing, which can
                        // never exactly match the PDF paragraph layout.
                        if (box && srcBBoxes && srcLines &&
                            srcBBoxes.length > 1 && srcBBoxes.length === srcLines.length) {

                            // Compute the CSS top of the first line using the TYPOGRAPHIC top
                            // (baseline − ascender × size) rather than the stored bbox top.
                            // Legacy annotations were extracted with MuPDF producing tight glyph
                            // bboxes (height ≈ font_size) while current MuPDF produces typographic
                            // bboxes (height ≈ ascender × size + |descender| × size).
                            // Using span origin data gives the correct position for both styles.
                            const _computeLineTop = (candidateSpans) => {
                                const s = Array.isArray(candidateSpans) && candidateSpans.length > 0
                                    ? candidateSpans[0] : null;
                                if (!s) return null;
                                const preferredTop = getSpanPreferredTopPts(s, fontSize);
                                return Number.isFinite(preferredTop) ? (preferredTop * scale) : null;
                            };
                            const _firstLineSpansForTop = _srcSpansRaw.filter(
                                (s) => spanOverlapsLineBBox(s, srcBBoxes[0], 1)
                            );
                            const _lineY0 = _computeLineTop(
                                _firstLineSpansForTop.length > 0 ? _firstLineSpansForTop : _srcSpansRaw
                            );
                            const rect_y0_css = _lineY0 !== null
                                ? _lineY0
                                : this.canvasHeight - (box.y + box.h) * scale;
                            const refY = Number(srcBBoxes[0][1]);  // y0 of first line bbox (used for relative offsets)

                            // Compute exclusive span-to-line assignments based on maximum bbox
                            // intersection area.  Each span is assigned to exactly one line
                            // (the one with the greatest overlap), preventing duplication when
                            // two source-line bboxes share the same top-Y — e.g. a form label
                            // "11" whose tall bbox straddles every sub-line, where Y-range
                            // overlap alone would assign the span to every line.
                            // Ties (equal area) resolve to the FIRST matching line index so that
                            // side-by-side sub-fields (e.g. "a" overlapping both line[0] and
                            // line[1] equally) are placed on the earlier/wider line rather than
                            // being hoisted to a separate line below.
                            const _spanLineAssignments = _srcSpansRaw.map((span) => {
                                const sb = span && (span.bbox || span.bBox);
                                if (!Array.isArray(sb) || sb.length < 4) return -1;
                                let bestLine = -1, bestArea = 0;
                                srcBBoxes.forEach((lineBbox, li) => {
                                    if (!Array.isArray(lineBbox) || lineBbox.length < 4) return;
                                    const area = getSpanLineOverlapArea(span, lineBbox, 1);
                                    if (area > bestArea) {
                                        bestArea = area;
                                        bestLine = li;
                                    }
                                });
                                return bestArea > 0 ? bestLine : -1;
                            });

                            // Helpers needed before single-line path definitions
                            const _mlGetSpanText = (span) => {
                                const displayText = getSourceSpanDisplayText(span);
                                return displayText !== '' ? displayText : String(span?.text ?? span?.rawText ?? '');
                            };
                            const _mlGetSpanColor = (span, fallback = '#000000') => {
                                if (span?.hex_color) return String(span.hex_color);
                                if (span?.color !== undefined && span?.color !== null) {
                                    if (typeof span.color === 'number') return '#' + span.color.toString(16).padStart(6, '0');
                                    const raw = String(span.color).trim();
                                    if (raw) return raw;
                                }
                                return fallback;
                            };
                            // Resolve CSS font-weight for a span, consulting _embeddedFonts for
                            // fonts whose weight is encoded only in the font name (e.g. -Bd, -Lt,
                            // -Demi, -BdIt, -BdOu).  The embedded font's css_weight is preferred
                            // over the span's stored font_weight because it uses more complete
                            // name-pattern analysis (compound tokens like BdIt/BdOu, Demi, etc.).
                            const _mlGetSpanWeight = (span) => {
                                if (!span) return 'normal';
                                const srcName = String(span.embedded_font_name || span.font || '').trim();
                                if (srcName && _embeddedFonts) {
                                    for (const [k, fd] of Object.entries(_embeddedFonts)) {
                                        if (String(fd.clean_name || k).trim().toLowerCase() === srcName.toLowerCase()) {
                                            return fd.css_weight || 'normal';
                                        }
                                    }
                                }
                                if (span.font_weight) return String(span.font_weight);
                                if (span.fontWeight)  return String(span.fontWeight);
                                if (span.bold)        return '700';
                                return 'normal';
                            };
                            const _mlNormFont = (n) => String(n || '').trim().replace(/PSMT$/i, '').replace(/PS(-\w+MT)$/i, '$1').toLowerCase();

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

                                // Use the pre-computed exclusive span-to-line assignments so each
                                // span appears in exactly one line.  This replaces the previous
                                // Y-range overlap filter, which could assign the same span to
                                // multiple lines when two source-line bboxes share the same Y-top
                                // (e.g. a form field whose tall label bbox encompasses a sub-line).
                                const lineSpans = _srcSpansRaw.filter((span, si) => _spanLineAssignments[si] === i);
                                // If no spans land on this line, skip it entirely — the span
                                // content will already appear within the line that claimed those
                                // spans via the assignment step above (e.g. "a" in "11a").
                                if (lineSpans.length === 0) continue;

                                // Override lineEl.style.top with the per-line typographic top
                                // when span origin data is available.  This gives perfect baseline
                                // alignment for every line individually, even when different lines
                                // have different tight-ascent characters (e.g. all-caps vs. ascenders).
                                (() => {
                                    const s = lineSpans[0];
                                    if (!s) return;
                                    const preferredTop = getSpanPreferredTopPts(s, lineStyle.fontSizePx / scale);
                                    if (!Number.isFinite(preferredTop)) return;
                                    lineEl.style.top = (preferredTop * scale).toFixed(2) + 'px';
                                })();

                                // Override annotation-level textColor with this line's primary
                                // span color.  ann.textColor reflects one span's color (often the
                                // last) and may differ from the color of spans on this line — e.g.
                                // when a multi-line block mixes white (background-section) text
                                // with dark visible text on individual lines.
                                lineEl.style.color = _mlGetSpanColor(lineSpans[0], ann.textColor || '#000000');

                                // Detect mixed fonts/weights/sizes/colors within this line
                                const lineHasMixed = lineSpans.length > 1 && lineSpans.some((s) => {
                                    const sf = _mlNormFont(s.embedded_font_name || s.font || '');
                                    const f0 = _mlNormFont(lineSpans[0].embedded_font_name || lineSpans[0].font || '');
                                    if (sf !== f0) return true;
                                    // Per-span color differences (e.g. colored bullet before black text)
                                    const sc = _mlGetSpanColor(s, ann.textColor || '#000000');
                                    const c0 = _mlGetSpanColor(lineSpans[0], ann.textColor || '#000000');
                                    if (sc.toLowerCase() !== c0.toLowerCase()) return true;
                                    // Font-size differences (e.g. superscripts/subscripts using same font family)
                                    const sz  = Number(s.font_size ?? s.fontSize) || 0;
                                    const sz0 = Number(lineSpans[0].font_size ?? lineSpans[0].fontSize) || 0;
                                    return sz0 > 0 && sz > 0 && Math.abs(sz - sz0) > 0.5;
                                });
                                // Detect significant positional X-gaps between spans (e.g. multi-column table cells)
                                const lineHasPositionalGaps = lineSpans.length > 1
                                    && lineSpans.every((s) => Array.isArray(s.origin) && s.origin.length >= 2)
                                    && lineSpans.some((s, i) => {
                                        if (i === 0) return false;
                                        const prevBbox = Array.isArray(lineSpans[i - 1].bbox) ? lineSpans[i - 1].bbox : null;
                                        if (!prevBbox || prevBbox.length < 3) return false;
                                        return Number(s.origin[0]) - Number(prevBbox[2]) > 4.0;
                                    });

                                if ((lineHasMixed || lineHasPositionalGaps) && lineSpans.every((s) => Array.isArray(s.origin) && s.origin.length >= 2)) {
                                    // Per-span rendering with individual font-weight/family
                                    lineEl.style.overflow = 'visible';
                                    const lineLeft = Number(bbox[0]);
                                    const lineText = (Array.isArray(srcLines) && srcLines[i]) ? String(srcLines[i]) : '';
                                    // Cursor into lineText so repeated words don't match the wrong occurrence.
                                    let lineTextCursor = 0;
                                    lineSpans.forEach((span, si) => {
                                        const spanEl       = document.createElement('span');
                                        const spanSrcName  = String(span.embedded_font_name || span.font || '').trim();
                                        const spanFontPx   = (Number(span.font_size ?? span.fontSize) || lineStyle.fontSizePx / scale) * scale;
                                        const spanFamily   = _resolveCssFont(spanSrcName, span.embedded_font_family || span.fontFamily || spanSrcName);
                                        const spanWeight   = _mlGetSpanWeight(span);
                                        const spanStyleVal = span.fontStyle || (span.italic ? 'italic' : lineStyle.fontStyle);
                                        let spanStretch    = 'normal';
                                        if (_embeddedFonts && spanSrcName) {
                                            for (const [k, fd] of Object.entries(_embeddedFonts)) {
                                                if (String(fd.clean_name || k).trim().toLowerCase() === spanSrcName.toLowerCase()) {
                                                    spanStretch = fd.css_stretch || 'normal';
                                                    break;
                                                }
                                            }
                                        }
                                        spanEl.style.position             = 'absolute';
                                        spanEl.style.left                 = ((Number(span.origin[0]) - lineLeft) * scale).toFixed(2) + 'px';
                                        spanEl.style.top                  = `${getSpanTopOffsetPx(span, Number(bbox[1])).toFixed(2)}px`;
                                        spanEl.style.fontFamily           = spanFamily;
                                        spanEl.style.fontSize             = spanFontPx.toFixed(2) + 'px';
                                        spanEl.style.fontWeight           = spanWeight;
                                        spanEl.style.fontStyle            = spanStyleVal;
                                        spanEl.style.fontStretch          = spanStretch;
                                        spanEl.style.letterSpacing        = '0';
                                        spanEl.style.lineHeight           = lineH.toFixed(2) + 'px';
                                        spanEl.style.whiteSpace           = 'pre';
                                        spanEl.style.color                = _mlGetSpanColor(span, ann.textColor || '#000000');
                                        _applyPdfTextCss(spanEl);
                                        // Recover inter-span trailing chars (spaces) from the source line.
                                        // When bbox[2] of this span == origin[0] of the next span (0 gap),
                                        // the word space is encoded in the bbox advance. Slicing the full
                                        // line text from this span to the next recovers that space as a
                                        // real character, bypassing the width-fit and rendering it naturally.
                                        // lineTextCursor advances forward so repeated words in the same line
                                        // don't cause the wrong occurrence to be matched.
                                        const coreText = _mlGetSpanText(span);
                                        let spanDisplayText = coreText;
                                        if (lineText) {
                                            const thisIdx = lineText.indexOf(coreText, lineTextCursor);
                                            if (thisIdx >= 0) {
                                                if (si < lineSpans.length - 1) {
                                                    const nextCore = _mlGetSpanText(lineSpans[si + 1]);
                                                    if (nextCore) {
                                                        const nextIdx = lineText.indexOf(nextCore, thisIdx + coreText.length);
                                                        if (nextIdx > thisIdx + coreText.length) {
                                                            spanDisplayText = lineText.slice(thisIdx, nextIdx);
                                                            lineTextCursor = nextIdx;
                                                        } else {
                                                            lineTextCursor = thisIdx + coreText.length;
                                                        }
                                                    } else {
                                                        lineTextCursor = thisIdx + coreText.length;
                                                    }
                                                } else {
                                                    lineTextCursor = thisIdx + coreText.length;
                                                }
                                            }
                                        }
                                        // Strip trailing whitespace: absolute span positions encode inter-span gaps;
                                        // keeping trailing spaces at a smaller font size (e.g. superscript) causes
                                        // the element to overflow into the next span's absolute position.
                                        spanEl.textContent = spanDisplayText.replace(/\s+$/, '') || spanDisplayText;
                                        const spanBBox = Array.isArray(span.bbox) ? span.bbox : null;
                                        if (spanBBox && spanBBox.length >= 4 && spanEl.textContent && !/^\s|\s$/.test(spanEl.textContent)) {
                                            _applyExactTextWidthFit(spanEl, {
                                                text: spanEl.textContent,
                                                targetWidthPx: (Number(spanBBox[2]) - Number(spanBBox[0])) * scale,
                                                fontSizePx: spanFontPx,
                                                fontFamily: spanFamily,
                                                fontWeight: spanWeight,
                                                fontStyle: spanStyleVal,
                                                fontStretch: spanStretch,
                                            });
                                        }
                                        lineEl.appendChild(spanEl);
                                    });
                                } else {
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
                                }
                                overlayEl.appendChild(lineEl);
                            }
                            return;  // skip appending main el
                        }

                        // Single-element (single-line or wrapped).
                        // Adjust el.style.top to the TYPOGRAPHIC top of the primary span when
                        // span origin data is available — this compensates for legacy annotations
                        // stored with tight glyph bbox tops (height ≈ font_size) vs current MuPDF
                        // typographic bboxes (height ≈ (ascender + |descender|) × size).
                        // The formula  top = origin_y − ascender × font_size  gives the correct
                        // CSS top regardless of which bbox style was stored.
                        (() => {
                            const s = _srcSpansRaw.length > 0 ? _srcSpansRaw[0] : null;
                            if (!s) return;
                            const preferredTop = getSpanPreferredTopPts(s, fontSize);
                            if (!Number.isFinite(preferredTop)) return;
                            el.style.top = (preferredTop * scale).toFixed(2) + 'px';
                        })();

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

                        // Filter source spans to only those whose Y bounding box overlaps this
                        // annotation's line.  The extractor occasionally groups spans from adjacent
                        // rows (e.g. row above and row below) into a single annotation, then only
                        // stores the LAST row's bbox in sourceLineBBoxes.  Rendering every span at
                        // top:0 inside the single positioned element stacks them all at the same
                        // Y coordinate — producing the "horrid overlap" visible in the viewer.
                        const srcSpans = (() => {
                            const lineBBox = Array.isArray(srcBBoxes) && srcBBoxes.length
                                ? srcBBoxes[0] : null;
                            if (!lineBBox || !Array.isArray(lineBBox) || lineBBox.length < 4) {
                                return _srcSpansRaw;
                            }
                            const TOL = 1; // 1pt tolerance — same as multi-line path uses
                            const filtered = _srcSpansRaw.filter((span) => {
                                const sb = Array.isArray(span?.bbox) && span.bbox.length >= 4
                                    ? span.bbox : null;
                                if (!sb) return true; // no positional data — keep
                                return spanOverlapsLineBBox(span, lineBBox, TOL);
                            });
                            return filtered;
                        })();
                        const getSpanDisplayText = (span) => getSourceSpanDisplayText(span);
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
                            // Cursor-based trailing-space recovery.
                            // For each absolutely-positioned span, include any inter-span characters
                            // (spaces, punctuation) as TRAILING content on the current span rather
                            // than as leading content on the next span.  Leading content on an
                            // absolutely-positioned span shifts its text rightward past its PDF
                            // origin, creating a visible extra gap.
                            let remaining = String(ann.text || '');
                            return srcSpans.map((span, si) => {
                                const coreText = getSpanDisplayText(span);
                                if (!coreText) return '';
                                const coreIdx = remaining.indexOf(coreText);
                                if (coreIdx < 0) return coreText;
                                // Skip any prefix before coreText (shouldn't normally occur)
                                remaining = remaining.slice(coreIdx);
                                // remaining now starts with coreText
                                if (si === srcSpans.length - 1) {
                                    remaining = '';
                                    return coreText; // last span: no trailing chars needed
                                }
                                // Look ahead: find where the next span's text starts so we can
                                // include any chars between this span and the next as trailing content.
                                const nextCoreText = getSpanDisplayText(srcSpans[si + 1]);
                                if (nextCoreText) {
                                    const nextIdx = remaining.indexOf(nextCoreText, coreText.length);
                                    if (nextIdx > coreText.length) {
                                        const chunkText = remaining.slice(0, nextIdx); // coreText + trailing chars
                                        remaining = remaining.slice(nextIdx); // advance to next span's start
                                        return chunkText;
                                    }
                                }
                                // No gap or next span not found: advance past coreText only
                                remaining = remaining.slice(coreText.length);
                                return coreText;
                            });
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
                        // Significant X gaps between adjacent spans (e.g. chapter number + large indent + title)
                        const hasPositionalGaps = canPositionMixedSpansAbsolutely && srcSpans.length > 1 && srcSpans.some((span, i) => {
                            if (i === 0) return false;
                            const prevBbox = Array.isArray(srcSpans[i - 1].bbox) ? srcSpans[i - 1].bbox : null;
                            const currOrigin = Array.isArray(span.origin) ? span.origin : null;
                            if (!prevBbox || prevBbox.length < 3 || !currOrigin) return false;
                            return Number(currOrigin[0]) - Number(prevBbox[2]) > 5.0;
                        });
                        const hasInlineLeaderSpans = srcSpans.length > 2
                            && srcSpans.some((span) => getSpanDisplayText(span).trim() === '.')
                            && srcSpans.some((span) => {
                                const text = getSpanDisplayText(span).trim();
                                return text && text !== '.';
                            });
                        const singleLineSourceTopPts = (() => {
                            const primarySpan = srcSpans[0] || _srcSpansRaw[0] || null;
                            if (primarySpan) {
                                const preferredTop = getSpanPreferredTopPts(primarySpan, fontSize);
                                if (Number.isFinite(preferredTop)) return preferredTop;
                            }
                            const lineBBox = Array.isArray(srcBBoxes) && srcBBoxes.length ? srcBBoxes[0] : null;
                            if (Array.isArray(lineBBox) && lineBBox.length >= 4) {
                                const top = Number(lineBBox[1]);
                                if (Number.isFinite(top)) return top;
                            }
                            return null;
                        })();
                        const getNumberedFieldGutterShiftPx = (_index) => 0;
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
                                spanEl.style.top         = `${getSpanTopOffsetPx(span, singleLineSourceTopPts).toFixed(2)}px`;
                                spanEl.style.fontFamily  = _resolveCssFont(spanSrcName, span.fontFamily || spanSrcName);
                                spanEl.style.fontSize    = spanFontPx.toFixed(2) + 'px';
                                spanEl.style.fontWeight  = span.fontWeight || fontWeight;
                                spanEl.style.fontStyle   = span.fontStyle || fontStyle;
                                spanEl.style.fontStretch = spanStretch;
                                spanEl.style.lineHeight  = spanFontPx.toFixed(2) + 'px';
                                spanEl.style.whiteSpace  = 'pre';
                                spanEl.style.color       = ann.textColor || '#000000';
                                _applyPdfTextCss(spanEl);
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
                        } else if (canPositionMixedSpansAbsolutely && (hasPerSpanColors || hasMixedSpans || hasInlineLeaderSpans || hasPositionalGaps)) {
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
                                spanEl.style.top         = `${getSpanTopOffsetPx(span, singleLineSourceTopPts).toFixed(2)}px`;
                                spanEl.style.fontFamily  = spanFamily;
                                spanEl.style.fontSize    = spanFontPx.toFixed(2) + 'px';
                                spanEl.style.fontWeight  = String(spanWeight);
                                spanEl.style.fontStyle   = spanStyle;
                                spanEl.style.fontStretch = spanStretch;
                                spanEl.style.lineHeight  = spanFontPx.toFixed(2) + 'px';
                                spanEl.style.whiteSpace  = 'pre';
                                spanEl.style.color       = spanColor;
                                _applyPdfTextCss(spanEl);
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
                                spanEl.style.color       = getSpanColorValue(span, ann.textColor || '#000000');
                                spanEl.style.whiteSpace  = 'pre';
                                _applyPdfTextCss(spanEl);
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
                                rotatedSpan.style.lineHeight = singleLineStyle.fontSizePx.toFixed(2) + 'px';
                                rotatedSpan.style.color = ann.textColor || '#000000';
                                rotatedSpan.style.transformOrigin = 'left top';
                                _applyPdfTextCss(rotatedSpan);
                                rotatedSpan.style.transform = primaryRotation < 0
                                    ? `translate(0px, ${box.h.toFixed(2)}px) rotate(${primaryRotation}deg)`
                                    : `translate(${box.w.toFixed(2)}px, 0px) rotate(${primaryRotation}deg)`;
                                el.style.overflow = 'visible';
                                el.appendChild(rotatedSpan);
                            } else {
                            // Prefer the span's render_text (which preserves PDF word/char spacing
                            // as literal space characters) over ann.text which is the compact form.
                            const _singleSpanText = _isExtractedLineFragment
                                ? String((Array.isArray(srcLines) && srcLines.length ? srcLines[0] : ann.text) || '')
                                : (
                                    srcSpans.length === 1 && srcSpans[0]?.render_text != null
                                        ? String(srcSpans[0].render_text)
                                        : (ann.text || '')
                                );
                            el.textContent = _singleSpanText;
                            if (cssWidth !== null) {
                                _applyExactTextWidthFit(el, {
                                    text: _singleSpanText,
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

                /* ── Annotation debug modal ── */
                async openDebugModal(ann) {
                    this.debugModalAnn = ann;
                    this.debugData     = null;
                    this.debugError    = null;
                    this.debugLoading  = true;
                    this.debugModal    = true;

                    try {
                        const docId = this.document?.id;
                        if (!docId) throw new Error('No document loaded.');
                        const base = '{{ route('pdfTests.annotationDebug', ['document' => '__ID__']) }}'
                            .replace('__ID__', encodeURIComponent(docId));
                        const params = ann.db_id
                            ? '?db_id=' + encodeURIComponent(ann.db_id)
                            : '?ann_id=' + encodeURIComponent(ann.id || '');
                        const resp = await fetch(base + params, {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                        });
                        const json = await resp.json();
                        if (!json.success) throw new Error(json.message || 'Failed to load debug data.');
                        this.debugData = json.data;
                    } catch (e) {
                        this.debugError = e.message || String(e);
                    } finally {
                        this.debugLoading = false;
                    }
                },

                /* ── Flag annotation modal ── */
                openFlagModal(ann) {
                    this.flagModalAnn = ann;
                    this.flagReason   = ann._flagReason || '';
                    this.flagImages   = (ann._flagImages || []).map(url => ({ dataUrl: url, name: '' }));
                    this.flagError    = null;
                    this.flagSaving   = false;
                    this.flagModal    = true;
                },

                // Compress a base64 data URL to max maxW wide, returns Promise<string>
                _compressImg(dataUrl, maxW = 900) {
                    return new Promise(resolve => {
                        const img = new Image();
                        img.onload = () => {
                            let w = img.width, h = img.height;
                            if (w > maxW) { h = Math.round(h * maxW / w); w = maxW; }
                            const c = document.createElement('canvas');
                            c.width = w; c.height = h;
                            c.getContext('2d').drawImage(img, 0, 0, w, h);
                            resolve(c.toDataURL('image/jpeg', 0.82));
                        };
                        img.onerror = () => resolve(dataUrl); // fallback uncompressed
                        img.src = dataUrl;
                    });
                },

                async _addImgFile(file) {
                    const reader = new FileReader();
                    reader.onload = async e => {
                        const compressed = await this._compressImg(e.target.result);
                        this.flagImages = [...this.flagImages, { dataUrl: compressed, name: file.name }];
                    };
                    reader.readAsDataURL(file);
                },

                removeFlagImage(idx) {
                    this.flagImages = this.flagImages.filter((_, i) => i !== idx);
                },

                async submitFlag(flagged) {
                    if (!this.flagModalAnn || !this.flagModalAnn.db_id) {
                        this.flagError = 'No db_id for this annotation — cannot flag.';
                        return;
                    }
                    this.flagSaving = true;
                    this.flagError  = null;
                    try {
                        const docId = this.document?.id;
                        const url = '{{ route('pdfTests.flagAnnotation', ['document' => '__ID__']) }}'
                            .replace('__ID__', encodeURIComponent(docId));
                        const resp = await fetch(url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            },
                            credentials: 'same-origin',
                            body: JSON.stringify({
                                db_id: this.flagModalAnn.db_id,
                                flagged,
                                flag_reason: this.flagReason || null,
                                flag_images: flagged ? this.flagImages.map(img => img.dataUrl) : [],
                            }),
                        });
                        const json = await resp.json();
                        if (!json.success) throw new Error(json.message || 'Save failed.');
                        // Update the annotation in the list reactively
                        const uid = this.flagModalAnn._uid;
                        const ann = this.annotations.find(a => a._uid === uid);
                        if (ann) {
                            ann._flagged    = json.flagged;
                            ann._flagReason = json.flag_reason || '';
                            ann._flagImages = json.flag_images || [];
                        }
                        this.flagModal = false;
                    } catch (e) {
                        this.flagError = e.message || String(e);
                    } finally {
                        this.flagSaving = false;
                    }
                },

            };
        }
    </script>
</x-filament-panels::page>
