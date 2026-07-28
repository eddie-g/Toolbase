<x-filament-panels::page>
    <div x-data="pdfPreview()" x-init="init()">

        {{-- HEADER --}}
        <div class="flex items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-xl font-bold text-gray-900 dark:text-white">PDF Preview</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    All pages rendered with annotations written by the Python writer.
                </p>
            </div>
        </div>

        {{-- LOAD BAR --}}
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
                        <span x-text="' · ' + pageCount + ' page' + (pageCount === 1 ? '' : 's')"></span>
                        <span x-text="' · ' + annotations.length + ' annotation' + (annotations.length === 1 ? '' : 's')"></span>
                    </div>
                </template>
            </div>
            <template x-if="error">
                <p class="mt-3 text-sm text-danger-600 dark:text-danger-400" x-text="error"></p>
            </template>
        </div>

        {{-- PAGES --}}
        <template x-if="pdfLoaded">
            <div class="space-y-6">

                {{-- Progress bar while rendering --}}
                <template x-if="renderingTotal > 0 && renderedCount < renderingTotal">
                    <div class="bg-white dark:bg-gray-800 rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Writing annotations…</span>
                            <span class="text-sm text-gray-500 dark:text-gray-400" x-text="renderedCount + ' / ' + renderingTotal"></span>
                        </div>
                        <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-2">
                            <div class="bg-primary-600 h-2 rounded-full transition-all duration-300"
                                 x-bind:style="'width:' + Math.round(renderedCount / renderingTotal * 100) + '%'"></div>
                        </div>
                    </div>
                </template>

                {{-- One card per page --}}
                <template x-for="pg in pageCount" :key="pg">
                    <div class="bg-white dark:bg-gray-800 rounded-2xl ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">

                        {{-- Page label --}}
                        <div class="flex items-center justify-between px-4 py-2.5 border-b border-gray-100 dark:border-gray-700">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                Page <span x-text="pg"></span> of <span x-text="pageCount"></span>
                            </span>
                            <template x-if="pageStatus[pg]">
                                <span class="text-xs" x-bind:class="{
                                    'text-success-600 dark:text-success-400': pageStatus[pg] === 'done',
                                    'text-primary-600 dark:text-primary-400': pageStatus[pg] === 'rendering',
                                    'text-danger-500 dark:text-danger-400':  pageStatus[pg] === 'error',
                                    'text-gray-400 dark:text-gray-500':      pageStatus[pg] === 'no-annotations',
                                }" x-text="{
                                    done: 'Written',
                                    rendering: 'Writing…',
                                    error: 'Error',
                                    'no-annotations': 'No annotations',
                                }[pageStatus[pg]] || ''"></span>
                            </template>
                        </div>

                        {{-- Canvas wrapper --}}
                        <div class="flex justify-center bg-gray-100 dark:bg-gray-950 py-4 px-4">
                            <canvas x-bind:id="'preview-canvas-' + pg" class="shadow-md"></canvas>
                        </div>
                    </div>
                </template>

            </div>
        </template>

    </div>

    <script>
    (function () {
        // PDF.js workers path (same as used by the main editor)
        if (typeof pdfjsLib !== 'undefined' && !pdfjsLib.GlobalWorkerOptions.workerSrc) {
            pdfjsLib.GlobalWorkerOptions.workerSrc = '/js/pdf.worker.min.mjs';
        }

        let _pdfDoc    = null;

        window.pdfPreview = function () {
            return {
                docIdInput:     new URLSearchParams(window.location.search).get('doc') || '',
                loading:        false,
                error:          null,
                document:       null,
                annotations:    [],
                pdfLoaded:      false,
                pageCount:      0,
                pageStatus:     {},   // { [pageNumber]: 'rendering'|'done'|'error'|'no-annotations' }
                renderingTotal: 0,
                renderedCount:  0,

                init() {
                    // Auto-load if ?doc= is in the URL
                    if (this.docIdInput) {
                        this.$nextTick(() => this.loadDocument());
                    }
                },

                async loadDocument() {
                    const id = parseInt(this.docIdInput, 10);
                    if (!id || id < 1) return;

                    this.loading      = true;
                    this.error        = null;
                    this.document     = null;
                    this.annotations  = [];
                    this.pdfLoaded    = false;
                    this.pageStatus   = {};
                    this.pageCount    = 0;
                    this.renderingTotal = 0;
                    this.renderedCount  = 0;
                    _pdfDoc           = null;

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
                        this.annotations = (data.annotations || []).filter(a => a.db_state !== 'deleted');

                        if (typeof pdfjsLib === 'undefined') throw new Error('PDF.js not loaded.');
                        _pdfDoc         = await pdfjsLib.getDocument(this.document.clean_url).promise;
                        this.pageCount  = _pdfDoc.numPages;
                        this.pdfLoaded  = true;

                        await this.$nextTick();
                        await this.renderAllPages();

                    } catch (e) {
                        this.error = e.message || String(e);
                    } finally {
                        this.loading = false;
                    }
                },

                async renderAllPages() {
                    if (!_pdfDoc) return;

                    // Group annotation db_ids by 0-based page index
                    const byPage = {};
                    this.annotations.forEach(a => {
                        const pi = Number(a.pageIndex) || 0;
                        if (!byPage[pi]) byPage[pi] = [];
                        if (a.db_id) byPage[pi].push(a.db_id);
                    });

                    this.renderingTotal = this.pageCount;
                    this.renderedCount  = 0;

                    // Render each page: paint PDF.js base then overlay writer PNG
                    for (let pg = 1; pg <= this.pageCount; pg++) {
                        const pi     = pg - 1;
                        const canvas = document.getElementById('preview-canvas-' + pg);
                        if (!canvas) { this.renderedCount++; continue; }

                        // Draw PDF.js base
                        const page     = await _pdfDoc.getPage(pg);
                        const viewport = page.getViewport({ scale: 1.5 });
                        canvas.width   = Math.round(viewport.width);
                        canvas.height  = Math.round(viewport.height);
                        const ctx      = canvas.getContext('2d');
                        await page.render({ canvasContext: ctx, viewport }).promise.catch(() => {});

                        // If no annotations, mark as done and move on
                        const ids = byPage[pi] || [];
                        if (ids.length === 0) {
                            this.pageStatus = { ...this.pageStatus, [pg]: 'no-annotations' };
                            this.renderedCount++;
                            continue;
                        }

                        this.pageStatus = { ...this.pageStatus, [pg]: 'rendering' };

                        try {
                            const url  = '{{ route('pdfTests.renderAnnotations', ['document' => '__ID__']) }}'
                                .replace('__ID__', encodeURIComponent(this.document.id));
                            const resp = await fetch(url, {
                                method:      'POST',
                                headers:     { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                                credentials: 'same-origin',
                                body:        new URLSearchParams({ state_ids: ids.join(','), page_index: String(pi), dpi: '150' }),
                            });
                            const result = await resp.json().catch(() => ({}));

                            if (result.success && result.image) {
                                await new Promise(resolve => {
                                    const img    = new Image();
                                    img.onload   = () => {
                                        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                                        resolve();
                                    };
                                    img.onerror  = resolve;
                                    img.src      = 'data:image/png;base64,' + result.image;
                                });
                                this.pageStatus = { ...this.pageStatus, [pg]: 'done' };
                            } else {
                                this.pageStatus = { ...this.pageStatus, [pg]: 'error' };
                            }
                        } catch (e) {
                            this.pageStatus = { ...this.pageStatus, [pg]: 'error' };
                        }

                        this.renderedCount++;
                    }
                },
            };
        };
    })();
    </script>

</x-filament-panels::page>
