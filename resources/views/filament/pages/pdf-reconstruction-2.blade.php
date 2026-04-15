<x-filament-panels::page>
    <div x-data="pdfRecon2()" x-init="init()" x-on:mousemove.window="handleWindowPointerMove($event)" x-on:mouseup.window="endDrag()" class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold text-gray-900 dark:text-white">PDF Reconstruction 2</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    Experimental flow: clean PDF background, visible text drawn on a canvas overlay, styled inline editor for the active text block.
                </p>
            </div>
            <a href="{{ route('filament.admin.pages.pdf-reconstruction') }}">
                <x-filament::button color="gray" size="sm" icon="heroicon-o-arrow-left">
                    Original Reconstruction
                </x-filament::button>
            </a>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-2xl ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between gap-3">
                <span class="text-sm font-semibold text-gray-900 dark:text-white">All Documents</span>
                <span class="text-xs text-gray-400 dark:text-gray-500">{{ $documents->count() }} total</span>
            </div>
            <div class="overflow-x-auto" style="max-height: 220px; overflow-y: auto;">
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
                            <tr class="cursor-pointer hover:bg-primary-50 dark:hover:bg-primary-900/20 transition-colors"
                                x-on:click="docIdInput = {{ $doc->id }}; loadDocument()">
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

        <div class="bg-white dark:bg-gray-800 rounded-2xl ring-1 ring-gray-950/5 dark:ring-white/10 p-5">
            <div class="flex items-end gap-3 flex-wrap">
                <div class="flex-1 max-w-xs">
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">Document ID</label>
                    <input
                        type="number"
                        min="1"
                        x-model="docIdInput"
                        x-on:keydown.enter="loadDocument()"
                        placeholder="e.g. 2851"
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500"
                    >
                </div>
                <x-filament::button x-on:click="loadDocument()" x-bind:disabled="loading || !docIdInput" icon="heroicon-o-arrow-down-tray" size="sm">
                    <span x-show="!loading">Load</span>
                    <span x-show="loading" x-cloak>Loading…</span>
                </x-filament::button>
                <template x-if="document">
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        <span class="font-medium text-gray-900 dark:text-white" x-text="document.name"></span>
                        <span x-text="' · ' + textAnnotations.length + ' text annotation' + (textAnnotations.length === 1 ? '' : 's')"></span>
                    </div>
                </template>
            </div>
            <template x-if="error">
                <p class="mt-3 text-sm text-danger-600 dark:text-danger-400" x-text="error"></p>
            </template>
        </div>

        <template x-if="document && pdfLoaded">
            <div class="space-y-4">
                <div class="bg-white dark:bg-gray-800 rounded-2xl ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
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

                        <div class="flex items-center gap-2">
                            <button type="button"
                                x-on:click="setZoom(Math.max(0.75, zoomLevel - 0.25))"
                                class="p-1 rounded text-gray-400 dark:text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                                title="Zoom out">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/>
                                </svg>
                            </button>
                            <input type="range"
                                min="0.75" max="3" step="0.25"
                                x-bind:value="zoomLevel"
                                x-on:input="setZoom(Number($event.target.value))"
                                class="w-28 h-1.5 rounded-full accent-primary-600 cursor-pointer">
                            <button type="button"
                                x-on:click="setZoom(Math.min(3, zoomLevel + 0.25))"
                                class="p-1 rounded text-gray-400 dark:text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                                title="Zoom in">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                                </svg>
                            </button>
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400 w-10 text-right tabular-nums" x-text="zoomPercent + '%'"></span>
                        </div>
                    </div>

                    <div class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400 border-b border-gray-100 dark:border-gray-700">
                        Clean PDF is the only background. All visible text on top is drawn into a canvas. Click a text block directly on the page to edit it.
                    </div>

                    <div class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between gap-3 flex-wrap">
                        <span>The focused block switches to a styled inline editor; other text stays on the overlay canvas.</span>
                        <span x-text="activeAnnotation ? 'Editing selected block' : (hoverUid ? 'Hovering editable block' : 'No block selected')"></span>
                    </div>

                    <div class="relative overflow-auto bg-gray-100 dark:bg-gray-950 flex justify-center items-start py-4 px-4" style="max-height: 78vh;">
                        <div class="relative inline-block" x-bind:style="'width:' + canvasWidth + 'px; height:' + canvasHeight + 'px;'">
                            <canvas x-ref="pdfCanvas"
                                class="block bg-white shadow-sm"
                                x-bind:width="canvasWidth"
                                x-bind:height="canvasHeight"></canvas>
                            <canvas x-ref="overlayCanvas"
                                class="absolute inset-0"
                                x-bind:width="canvasWidth"
                                x-bind:height="canvasHeight"
                                x-bind:style="dragState.active ? 'cursor:move;' : (hoverUid || activeUid ? 'cursor:text;' : 'cursor:default;')"
                                x-on:mousemove="handleOverlayPointerMove($event)"
                                x-on:mouseleave="clearHoverAnnotation()"
                                x-on:click="handleOverlayClick($event)"></canvas>

                            <div
                                x-ref="activeEditor"
                                x-show="activeAnnotation"
                                x-cloak
                                contenteditable="true"
                                x-bind:style="activeEditorStyle()"
                                x-on:input="handleActiveEditorInput($event)"
                                x-on:mousedown.stop
                                x-on:mouseup.stop
                                x-on:mousemove.stop
                                x-on:click.stop
                                x-on:keydown.escape.prevent="clearActiveAnnotation()"
                                spellcheck="false"
                                class="absolute overflow-hidden focus:outline-none"></div>

                            <button
                                type="button"
                                x-show="activeAnnotation"
                                x-cloak
                                x-bind:style="activeMoveHandleStyle()"
                                x-on:mousedown.prevent.stop="beginDrag($event)"
                                class="absolute inline-flex items-center gap-1 rounded-md bg-primary-600 px-2 py-1 text-[11px] font-medium text-white shadow-sm hover:bg-primary-700 focus:outline-none">
                                <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path d="M7 4a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Zm0 6a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Zm-1.5 7.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM14.5 5.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm1.5 4.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Zm-1.5 7.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" />
                                </svg>
                                <span x-text="dragState.active ? 'Moving' : 'Move'"></span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-2xl ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Selection</h3>
                        <span class="text-xs text-gray-400 dark:text-gray-500" x-text="pageTextAnnotations.length + ' text block(s) on this page'"></span>
                    </div>

                    <div class="px-4 py-4 space-y-3 text-sm">
                        <template x-if="!activeAnnotation">
                            <p class="text-gray-500 dark:text-gray-400">
                                Click any text block on the page to edit it. Drag the move handle above the selected block to reposition it.
                            </p>
                        </template>

                        <template x-if="activeAnnotation">
                            <div class="space-y-3">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-xs font-mono text-gray-400 dark:text-gray-500" x-text="activeAnnotation.id || activeAnnotation.db_id || activeAnnotation._uid"></span>
                                    <span class="text-[11px] font-medium text-gray-400 dark:text-gray-500" x-text="annotationFontLabel(activeAnnotation)"></span>
                                </div>
                                <p class="text-gray-800 dark:text-gray-100 leading-snug break-words" x-text="previewText(activeAnnotation)"></p>
                                <div class="flex items-center gap-2">
                                    <button type="button"
                                        x-on:click="focusActiveAnnotation()"
                                        class="text-xs font-medium px-2.5 py-1 rounded-lg bg-primary-600 text-white hover:bg-primary-700 transition-colors">
                                        Focus Input
                                    </button>
                                    <button type="button"
                                        x-on:click="clearActiveAnnotation()"
                                        class="text-xs font-medium px-2.5 py-1 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                                        Clear Selection
                                    </button>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    Position:
                                    <span class="font-mono" x-text="activeAnnotationPositionLabel()"></span>
                                </p>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </template>
    </div>

@once
    @push('styles')
        <style>
            [x-cloak] { display: none !important; }
        </style>
    @endpush

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js"></script>
        <script>
            if (typeof pdfjsLib !== 'undefined') {
                pdfjsLib.GlobalWorkerOptions.workerSrc =
                    'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';
            }
        </script>

        <script>
        function pdfRecon2() {
            let pdfDoc = null;
            let renderTask = null;
            let measureCanvas = null;

            const FONT_MAP = {
                Arial: 'Arial, Helvetica, sans-serif',
                'Arial,Bold': 'Arial, Helvetica, sans-serif',
                ArialMT: 'Arial, Helvetica, sans-serif',
                'Arial-ItalicMT': 'Arial, Helvetica, sans-serif',
                'Arial-BoldMT': 'Arial, Helvetica, sans-serif',
                'Arial-BoldItalicMT': 'Arial, Helvetica, sans-serif',
                Helvetica: 'Arial, Helvetica, sans-serif',
                FreeSans: '"Liberation Sans", Arial, Helvetica, sans-serif',
                FreeSerif: '"Liberation Serif", "Times New Roman", Times, serif',
                FreeMono: '"Liberation Mono", "Courier New", Courier, monospace',
                Times: '"Times New Roman", Times, serif',
                TimesRoman: '"Times New Roman", Times, serif',
                'Times New Roman': '"Times New Roman", Times, serif',
                Verdana: 'Verdana, Geneva, sans-serif',
                Tahoma: 'Tahoma, Geneva, sans-serif',
                DejaVuSans: '"DejaVu Sans", Arial, Helvetica, sans-serif',
                DejaVuSerif: '"DejaVu Serif", "Times New Roman", serif',
                Courier: '"Courier New", Courier, monospace',
            };

            const normalizeFontName = (name) => {
                let cleaned = String(name || '').replace(/^PDF_/i, '').trim();
                if (!cleaned) return '';
                cleaned = cleaned.replace(/PSMT$/i, '').replace(/PS(-\w+MT)$/i, '$1').trim();
                if (cleaned.includes('+')) {
                    const parts = cleaned.split('+', 2);
                    if (parts[0].length === 6) {
                        cleaned = parts[1];
                    }
                }
                return cleaned;
            };

            const fallbackFontFamily = (name, family = '') => {
                const raw = normalizeFontName(name || family);
                if (!raw) return FONT_MAP.Helvetica;
                const key = raw.replace(/['"]/g, '').trim();
                if (FONT_MAP[key]) return FONT_MAP[key];
                const simple = key.split(/[-_,]/)[0];
                if (FONT_MAP[simple]) return FONT_MAP[simple];
                return FONT_MAP.Helvetica;
            };

            const ensureMeasureContext = () => {
                if (measureCanvas instanceof HTMLCanvasElement) {
                    return measureCanvas.getContext('2d');
                }
                measureCanvas = document.createElement('canvas');
                return measureCanvas.getContext('2d');
            };

            const ctxFont = (style) => {
                const fontStyle = String(style.fontStyle || 'normal');
                const fontWeight = String(style.fontWeight || '400');
                const fontSizePx = Math.max(1, Number(style.fontSizePx) || 1);
                return `${fontStyle} ${fontWeight} ${fontSizePx}px ${style.fontFamily}`;
            };

            const measureTextWidth = (text, style) => {
                const ctx = ensureMeasureContext();
                if (!ctx) return 0;
                ctx.font = ctxFont(style);
                return ctx.measureText(String(text || '')).width || 0;
            };

            return {
                docIdInput: '',
                loading: false,
                error: null,
                document: null,
                pdfLoaded: false,
                annotations: [],
                editedTexts: {},
                activeUid: null,
                hoverUid: null,
                dragState: {
                    active: false,
                    uid: null,
                    offsetXPts: 0,
                    offsetYPts: 0,
                },
                pageCount: 0,
                currentPage: 1,
                canvasWidth: 0,
                canvasHeight: 0,
                pageWidthPts: 0,
                pageHeightPts: 0,
                zoomLevel: 1.5,

                init() {},

                get scale() {
                    return this.pageWidthPts > 0 ? (this.canvasWidth / this.pageWidthPts) : this.zoomLevel;
                },

                get zoomPercent() {
                    return Math.round(this.zoomLevel * 100);
                },

                get textAnnotations() {
                    return this.annotations.filter((annotation) => String(annotation.type || 'text') === 'text');
                },

                get pageTextAnnotations() {
                    return this.textAnnotations.filter(
                        (annotation) => (Number(annotation.pageIndex) || 0) === this.currentPage - 1
                    );
                },

                get activeAnnotation() {
                    return this.annotations.find((annotation) => annotation._uid === this.activeUid) || null;
                },

                activeText() {
                    const annotation = this.activeAnnotation;
                    if (!annotation) return '';
                    return this.editedTexts[annotation._uid] ?? String(annotation.text || '');
                },

                previewText(annotation) {
                    return String(this.editedTexts[annotation._uid] ?? annotation.text ?? '')
                        .replace(/\s+/g, ' ')
                        .trim()
                        .slice(0, 140) || '(empty text)';
                },

                annotationFontLabel(annotation) {
                    const span = Array.isArray(annotation?.sourceSpans) && annotation.sourceSpans.length > 0
                        ? annotation.sourceSpans[0]
                        : null;
                    return normalizeFontName(span?.embedded_font_name || span?.font || annotation?.fontSourceName || annotation?.fontFamily || 'default');
                },

                activeAnnotationPositionLabel() {
                    const annotation = this.activeAnnotation;
                    const box = annotation ? this.resolveAnnBox(annotation) : null;
                    if (!box) return '—';
                    return `${box.x.toFixed(2)}, ${box.y.toFixed(2)}`;
                },

                resolveAnnBox(annotation) {
                    const px = Number(annotation.pdfX);
                    const py = Number(annotation.pdfY);
                    const pw = Number(annotation.pdfWidth);
                    const ph = Number(annotation.pdfHeight);
                    if ([px, py, pw, ph].every(Number.isFinite) && pw > 0 && ph > 0) {
                        return { x: px, y: py, w: pw, h: ph };
                    }

                    const sL = Number(annotation.sourceBlockLeft);
                    const sT = Number(annotation.sourceBlockTop);
                    const sW = Number(annotation.sourceBlockWidth);
                    const sH = Number(annotation.sourceBlockHeight);
                    const pageHeight = Number(annotation.sourcePageHeight);
                    if ([sL, sT, sW, sH, pageHeight].every(Number.isFinite) && sW > 0 && sH > 0) {
                        return { x: sL, y: pageHeight - (sT + sH), w: sW, h: sH };
                    }

                    return null;
                },

                resolveOriginalAnnBox(annotation) {
                    const box = annotation?._originalBox;
                    if (box && [box.x, box.y, box.w, box.h].every(Number.isFinite)) {
                        return box;
                    }
                    return this.resolveAnnBox(annotation);
                },

                annotationOffset(annotation) {
                    const current = this.resolveAnnBox(annotation);
                    const original = this.resolveOriginalAnnBox(annotation);
                    if (!current || !original) {
                        return { dx: 0, dy: 0 };
                    }
                    return {
                        dx: current.x - original.x,
                        dy: current.y - original.y,
                    };
                },

                setAnnotationBox(annotation, nextBox) {
                    if (!annotation || !nextBox) return;
                    annotation.pdfX = Number(nextBox.x);
                    annotation.pdfY = Number(nextBox.y);
                    annotation.pdfWidth = Number(nextBox.w);
                    annotation.pdfHeight = Number(nextBox.h);

                    if (annotation.annotation_data && typeof annotation.annotation_data === 'object') {
                        annotation.annotation_data.pdfX = Number(nextBox.x);
                        annotation.annotation_data.pdfY = Number(nextBox.y);
                        annotation.annotation_data.pdfWidth = Number(nextBox.w);
                        annotation.annotation_data.pdfHeight = Number(nextBox.h);
                    }
                },

                async loadDocument() {
                    const id = parseInt(this.docIdInput, 10);
                    if (!id || id < 1) return;

                    this.loading = true;
                    this.error = null;
                    this.document = null;
                    this.annotations = [];
                    this.editedTexts = {};
                    this.activeUid = null;
                    this.hoverUid = null;
                    this.dragState = {
                        active: false,
                        uid: null,
                        offsetXPts: 0,
                        offsetYPts: 0,
                    };
                    this.pdfLoaded = false;
                    this.pageCount = 0;
                    this.currentPage = 1;
                    pdfDoc = null;

                    try {
                        const infoUrl = '{{ route('pdfTests.documentInfo', ['document' => '__ID__']) }}'
                            .replace('__ID__', encodeURIComponent(id));
                        const response = await fetch(infoUrl, {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                        });

                        if (response.status === 404) throw new Error('Document #' + id + ' not found.');
                        if (!response.ok) {
                            const body = await response.json().catch(() => ({}));
                            throw new Error(body.message || ('HTTP ' + response.status));
                        }

                        const payload = await response.json();
                        if (!payload.success) throw new Error(payload.message || 'Failed to load document info.');

                        this.document = payload.document;
                        this.annotations = (payload.annotations || []).map((annotation, index) => {
                            const hydrated = {
                                ...annotation,
                                _uid: String(annotation.id || annotation.db_id || '') + '_' + index,
                            };
                            const originalBox = this.resolveAnnBox(hydrated);
                            hydrated._originalBox = originalBox ? { ...originalBox } : null;
                            return hydrated;
                        });

                        this.editedTexts = this.annotations.reduce((carry, annotation) => {
                            carry[annotation._uid] = String(annotation.text || '');
                            return carry;
                        }, {});

                        await this.loadPdf(this.document.clean_url);
                    } catch (error) {
                        this.error = error.message || String(error);
                    } finally {
                        this.loading = false;
                    }
                },

                async loadPdf(url) {
                    if (typeof pdfjsLib === 'undefined') {
                        throw new Error('PDF.js not loaded.');
                    }
                    pdfDoc = await pdfjsLib.getDocument(url).promise;
                    this.pageCount = pdfDoc.numPages;
                    this.currentPage = 1;
                    this.pdfLoaded = true;
                    await this.$nextTick();
                    await this.renderCurrentPage();
                },

                async renderCurrentPage() {
                    if (!pdfDoc) return;
                    if (renderTask) {
                        renderTask.cancel();
                        renderTask = null;
                    }

                    const page = await pdfDoc.getPage(this.currentPage);
                    const viewport = page.getViewport({ scale: this.zoomLevel });
                    this.pageWidthPts = page.view[2];
                    this.pageHeightPts = page.view[3];
                    this.canvasWidth = Math.round(viewport.width);
                    this.canvasHeight = Math.round(viewport.height);

                    await this.$nextTick();

                    const baseCanvas = this.$refs.pdfCanvas;
                    const overlayCanvas = this.$refs.overlayCanvas;
                    if (!baseCanvas || !overlayCanvas) return;

                    baseCanvas.width = this.canvasWidth;
                    baseCanvas.height = this.canvasHeight;
                    overlayCanvas.width = this.canvasWidth;
                    overlayCanvas.height = this.canvasHeight;

                    const context = baseCanvas.getContext('2d');
                    renderTask = page.render({ canvasContext: context, viewport });
                    await renderTask.promise.catch(() => {});
                    renderTask = null;

                    this.redrawOverlay();
                    this.syncActiveEditor();
                },

                async prevPage() {
                    if (this.currentPage <= 1) return;
                    this.currentPage -= 1;
                    await this.renderCurrentPage();
                },

                async nextPage() {
                    if (this.currentPage >= this.pageCount) return;
                    this.currentPage += 1;
                    await this.renderCurrentPage();
                },

                async setZoom(value) {
                    this.zoomLevel = Number(value) || this.zoomLevel;
                    await this.renderCurrentPage();
                },

                clearActiveAnnotation() {
                    this.endDrag();
                    this.activeUid = null;
                    this.syncActiveEditor();
                    this.redrawOverlay();
                },

                clearHoverAnnotation() {
                    this.hoverUid = null;
                    this.redrawOverlay();
                },

                async selectAnnotation(annotation) {
                    const targetPage = (Number(annotation.pageIndex) || 0) + 1;
                    if (targetPage !== this.currentPage) {
                        this.currentPage = targetPage;
                        await this.renderCurrentPage();
                    }

                    this.activeUid = annotation._uid;
                    this.redrawOverlay();
                    await this.$nextTick();
                    this.syncActiveEditor(true);
                },

                focusActiveAnnotation() {
                    this.syncActiveEditor(true);
                },

                activeTextareaDisplayStyle(annotation) {
                    const baseStyle = this.sourceStyle(annotation, 0);
                    const fontSizePx = baseStyle.fontSizePt * this.scale;
                    const lineHeightPx = this.blockLineHeightPx(annotation, 0, this.scale);
                    return {
                        fontFamily: baseStyle.fontFamily,
                        fontSizePx,
                        fontWeight: baseStyle.fontWeight,
                        fontStyle: baseStyle.fontStyle,
                        color: baseStyle.fillStyle,
                        lineHeightPx,
                    };
                },

                annotationRectCss(annotation) {
                    const box = this.resolveAnnBox(annotation);
                    if (!box) return null;
                    const scale = this.scale;
                    return {
                        left: box.x * scale,
                        top: this.canvasHeight - (box.y + box.h) * scale,
                        width: box.w * scale,
                        height: box.h * scale,
                    };
                },

                activeMoveHandleStyle() {
                    const annotation = this.activeAnnotation;
                    const rect = annotation ? this.annotationRectCss(annotation) : null;
                    if (!rect) {
                        return 'display:none;';
                    }

                    const top = Math.max(0, rect.top - 28);
                    return [
                        'position:absolute',
                        `left:${rect.left.toFixed(2)}px`,
                        `top:${top.toFixed(2)}px`,
                        'z-index:6',
                        'cursor:move',
                    ].join(';');
                },

                findAnnotationAtCanvasPoint(x, y) {
                    const hits = this.pageTextAnnotations
                        .map((annotation) => ({
                            annotation,
                            rect: this.annotationRectCss(annotation),
                        }))
                        .filter(({ rect }) => rect && rect.width > 0 && rect.height > 0)
                        .filter(({ rect }) => (
                            x >= rect.left
                            && x <= rect.left + rect.width
                            && y >= rect.top
                            && y <= rect.top + rect.height
                        ))
                        .sort((left, right) => (left.rect.width * left.rect.height) - (right.rect.width * right.rect.height));

                    return hits.length ? hits[0].annotation : null;
                },

                overlayPointFromEvent(event) {
                    return this.overlayPointFromClientPoint(event?.clientX, event?.clientY);
                },

                overlayPointFromClientPoint(clientX, clientY) {
                    const canvas = this.$refs.overlayCanvas;
                    if (!canvas) return null;
                    const rect = canvas.getBoundingClientRect();
                    if (!rect.width || !rect.height) return null;
                    return {
                        x: (clientX - rect.left) * (canvas.width / rect.width),
                        y: (clientY - rect.top) * (canvas.height / rect.height),
                    };
                },

                pdfPointFromClientPoint(clientX, clientY) {
                    const point = this.overlayPointFromClientPoint(clientX, clientY);
                    if (!point) return null;
                    return {
                        x: point.x / this.scale,
                        y: (this.canvasHeight - point.y) / this.scale,
                    };
                },

                handleOverlayPointerMove(event) {
                    if (this.dragState.active) return;
                    const point = this.overlayPointFromEvent(event);
                    if (!point) return;
                    const annotation = this.findAnnotationAtCanvasPoint(point.x, point.y);
                    const nextHoverUid = annotation?._uid || null;
                    if (nextHoverUid === this.hoverUid) return;
                    this.hoverUid = nextHoverUid;
                    this.redrawOverlay();
                },

                async handleOverlayClick(event) {
                    if (this.dragState.active) return;
                    const point = this.overlayPointFromEvent(event);
                    if (!point) return;
                    const annotation = this.findAnnotationAtCanvasPoint(point.x, point.y);
                    if (!annotation) {
                        this.clearActiveAnnotation();
                        return;
                    }
                    await this.selectAnnotation(annotation);
                },

                beginDrag(event) {
                    const annotation = this.activeAnnotation;
                    const box = annotation ? this.resolveAnnBox(annotation) : null;
                    const point = annotation ? this.pdfPointFromClientPoint(event.clientX, event.clientY) : null;
                    if (!annotation || !box || !point) return;

                    this.dragState = {
                        active: true,
                        uid: annotation._uid,
                        offsetXPts: point.x - box.x,
                        offsetYPts: point.y - box.y,
                    };
                    this.hoverUid = annotation._uid;
                    this.redrawOverlay();
                },

                handleWindowPointerMove(event) {
                    if (!this.dragState.active) return;
                    const annotation = this.annotations.find((item) => item._uid === this.dragState.uid) || null;
                    const box = annotation ? this.resolveAnnBox(annotation) : null;
                    const point = annotation ? this.pdfPointFromClientPoint(event.clientX, event.clientY) : null;
                    if (!annotation || !box || !point) return;

                    const nextX = Math.min(Math.max(0, point.x - this.dragState.offsetXPts), Math.max(0, this.pageWidthPts - box.w));
                    const nextY = Math.min(Math.max(0, point.y - this.dragState.offsetYPts), Math.max(0, this.pageHeightPts - box.h));

                    this.setAnnotationBox(annotation, {
                        x: nextX,
                        y: nextY,
                        w: box.w,
                        h: box.h,
                    });

                    this.redrawOverlay();
                },

                endDrag() {
                    if (!this.dragState.active) return;
                    this.dragState = {
                        active: false,
                        uid: null,
                        offsetXPts: 0,
                        offsetYPts: 0,
                    };
                    this.redrawOverlay();
                },

                activeEditorStyle() {
                    const annotation = this.activeAnnotation;
                    const box = annotation ? this.resolveAnnBox(annotation) : null;
                    if (!annotation || !box) {
                        return 'display:none;';
                    }

                    const scale = this.scale;
                    const left = box.x * scale;
                    const top = this.canvasHeight - (box.y + box.h) * scale;
                    const width = Math.max(2, box.w * scale);
                    const height = Math.max(18, box.h * scale);

                    return [
                        'position:absolute',
                        `left:${left.toFixed(2)}px`,
                        `top:${top.toFixed(2)}px`,
                        `width:${width.toFixed(2)}px`,
                        `height:${height.toFixed(2)}px`,
                        'caret-color:#2563eb',
                        'padding:0',
                        'margin:0',
                        'background:transparent',
                        'border:none',
                        'outline:none',
                        'border-radius:0',
                        'overflow:hidden',
                        'pointer-events:auto',
                        'user-select:text',
                        'cursor:text',
                        'z-index:5',
                    ].join(';');
                },

                escapeEditorHtml(value) {
                    return String(value ?? '')
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;');
                },

                editorLineStyle(annotation, lineIndex) {
                    const style = this.sourceStyle(annotation, lineIndex);
                    const lineHeightPx = this.blockLineHeightPx(annotation, lineIndex, this.scale);
                    return [
                        `font-family:${style.fontFamily}`,
                        `font-size:${(style.fontSizePt * this.scale).toFixed(2)}px`,
                        `font-weight:${style.fontWeight}`,
                        `font-style:${style.fontStyle}`,
                        `line-height:${lineHeightPx.toFixed(2)}px`,
                        `min-height:${lineHeightPx.toFixed(2)}px`,
                        `color:${style.fillStyle}`,
                        'white-space:pre-wrap',
                        'overflow-wrap:break-word',
                        'word-break:break-word',
                    ].join(';');
                },

                renderActiveEditorHtml(annotation) {
                    const lines = String(this.activeText() || '').split('\n');
                    const maxSourceIndex = Math.max(0, (Array.isArray(annotation?.sourceLineBBoxes) ? annotation.sourceLineBBoxes.length : 0) - 1);

                    return (lines.length ? lines : ['']).map((lineText, index) => {
                        const sourceIndex = Math.min(index, maxSourceIndex);
                        const style = this.editorLineStyle(annotation, sourceIndex);
                        const escaped = this.escapeEditorHtml(lineText);
                        return `<div style="${style}">${escaped || '<br>'}</div>`;
                    }).join('');
                },

                syncActiveEditor(focus = false) {
                    const editor = this.$refs.activeEditor;
                    const annotation = this.activeAnnotation;
                    if (!editor) return;
                    if (!annotation) {
                        editor.innerHTML = '';
                        return;
                    }

                    if (document.activeElement !== editor || focus) {
                        editor.innerHTML = this.renderActiveEditorHtml(annotation);
                    }

                    if (focus) {
                        editor.focus({ preventScroll: true });
                        this.placeCaretAtEnd(editor);
                    }
                },

                placeCaretAtEnd(element) {
                    const selection = window.getSelection();
                    if (!selection) return;
                    const range = document.createRange();
                    range.selectNodeContents(element);
                    range.collapse(false);
                    selection.removeAllRanges();
                    selection.addRange(range);
                },

                handleActiveEditorInput(event) {
                    const annotation = this.activeAnnotation;
                    if (!annotation) return;
                    const nextText = String(event.currentTarget?.innerText || '')
                        .replace(/\r/g, '')
                        .replace(/\u00a0/g, ' ')
                        .replace(/\n$/, '');
                    this.editedTexts = {
                        ...this.editedTexts,
                        [annotation._uid]: nextText,
                    };
                },

                lineSpans(annotation, lineIndex) {
                    const lineBBoxes = Array.isArray(annotation?.sourceLineBBoxes) ? annotation.sourceLineBBoxes : [];
                    const spans = Array.isArray(annotation?.sourceSpans) ? annotation.sourceSpans : [];
                    const lineBBox = Array.isArray(lineBBoxes[lineIndex]) ? lineBBoxes[lineIndex] : null;
                    if (!lineBBox) return [];

                    return spans.filter((span) => {
                        const bbox = Array.isArray(span?.bbox) ? span.bbox : null;
                        if (!bbox || bbox.length < 4) return false;
                        const xi = Math.max(Number(bbox[0]), Number(lineBBox[0]) - 0.5);
                        const yi = Math.max(Number(bbox[1]), Number(lineBBox[1]) - 0.5);
                        const xa = Math.min(Number(bbox[2]), Number(lineBBox[2]) + 0.5);
                        const ya = Math.min(Number(bbox[3]), Number(lineBBox[3]) + 0.5);
                        return (xa - xi) > 0 && (ya - yi) > 0;
                    });
                },

                sourceStyle(annotation, lineIndex = 0) {
                    const lineSpans = this.lineSpans(annotation, lineIndex);
                    const span = lineSpans[0] || (Array.isArray(annotation?.sourceSpans) ? annotation.sourceSpans[0] : null) || null;
                    const fontName = span?.embedded_font_name || span?.font || annotation?.fontSourceName || annotation?.fontFamily || '';
                    const fontSizePt = Number(span?.font_size ?? span?.fontSize ?? annotation?.fontSize) || 12;
                    return {
                        fontFamily: fallbackFontFamily(fontName, span?.embedded_font_family || annotation?.fontFamily || ''),
                        fontSizePt,
                        fontWeight: String(span?.font_weight || span?.fontWeight || annotation?.fontWeight || (span?.bold ? '700' : '400') || '400'),
                        fontStyle: span?.fontStyle || (span?.italic || annotation?.fontStyle === 'italic' ? 'italic' : 'normal'),
                        fillStyle: String(span?.hex_color || annotation?.textColor || '#000000'),
                    };
                },

                blockLineHeightPx(annotation, lineIndex = 0, scale = this.scale) {
                    const lineBBox = Array.isArray(annotation?.sourceLineBBoxes?.[lineIndex]) ? annotation.sourceLineBBoxes[lineIndex] : null;
                    const sourceHeight = lineBBox ? Math.max(0, Number(lineBBox[3]) - Number(lineBBox[1])) * scale : 0;
                    const fontHeight = this.sourceStyle(annotation, lineIndex).fontSizePt * scale * 1.18;
                    return Math.max(12, sourceHeight || 0, fontHeight);
                },

                wrapParagraph(text, maxWidthPx, style) {
                    const content = String(text || '');
                    if (!content) return [''];

                    const words = content.split(/(\s+)/).filter((piece) => piece !== '');
                    const lines = [];
                    let current = '';

                    words.forEach((piece) => {
                        const candidate = current + piece;
                        if (!current || measureTextWidth(candidate, style) <= maxWidthPx) {
                            current = candidate;
                            return;
                        }

                        if (current.trim()) {
                            lines.push(current.trimEnd());
                            current = piece.trimStart();
                            return;
                        }

                        let remainder = piece;
                        while (remainder) {
                            let splitIndex = remainder.length;
                            while (splitIndex > 1 && measureTextWidth(remainder.slice(0, splitIndex), style) > maxWidthPx) {
                                splitIndex -= 1;
                            }
                            lines.push(remainder.slice(0, splitIndex));
                            remainder = remainder.slice(splitIndex);
                        }
                        current = '';
                    });

                    if (current || !lines.length) {
                        lines.push(current.trimEnd());
                    }

                    return lines;
                },

                buildEditedLines(annotation, currentText, scale) {
                    const box = this.resolveAnnBox(annotation);
                    if (!box) return [];
                    const offset = this.annotationOffset(annotation);

                    const paragraphs = String(currentText || '').split('\n');
                    const lineBBoxes = Array.isArray(annotation?.sourceLineBBoxes) ? annotation.sourceLineBBoxes : [];
                    const lines = [];
                    const maxWidthPx = Math.max(10, box.w * scale);
                    let cursorTopPts = lineBBoxes.length > 0
                        ? Number(lineBBoxes[0][1]) + offset.dy
                        : (this.pageHeightPts - box.y - box.h);

                    paragraphs.forEach((paragraph, paragraphIndex) => {
                        const style = this.sourceStyle(annotation, Math.min(paragraphIndex, Math.max(0, lineBBoxes.length - 1)));
                        const stylePx = {
                            ...style,
                            fontSizePx: style.fontSizePt * scale,
                        };
                        const wrapped = this.wrapParagraph(paragraph, maxWidthPx, stylePx);

                        wrapped.forEach((lineText, wrappedIndex) => {
                            const sourceIndex = lines.length;
                            const sourceBBox = Array.isArray(lineBBoxes[sourceIndex]) ? lineBBoxes[sourceIndex] : null;
                            const lineHeightPx = this.blockLineHeightPx(annotation, Math.min(sourceIndex, Math.max(0, lineBBoxes.length - 1)), scale);
                            const topPts = sourceBBox
                                ? Number(sourceBBox[1]) + offset.dy
                                : (cursorTopPts + ((wrappedIndex === 0 && paragraphIndex === 0 && lines.length === 0) ? 0 : (lineHeightPx / scale)));
                            const baselinePts = (() => {
                                const spans = this.lineSpans(annotation, sourceIndex);
                                const span = spans[0] || null;
                                if (Array.isArray(span?.origin) && span.origin.length >= 2) {
                                    return Number(span.origin[1]) + offset.dy;
                                }
                                return topPts + (lineHeightPx / scale) * 0.82;
                            })();

                            lines.push({
                                text: lineText,
                                xPts: box.x,
                                topPts,
                                baselinePts,
                                lineHeightPx,
                                style: stylePx,
                            });
                            cursorTopPts = topPts;
                        });
                    });

                    return lines;
                },

                drawOriginalSource(annotation, ctx, scale) {
                    const spans = Array.isArray(annotation?.sourceSpans) ? annotation.sourceSpans : [];
                    if (!spans.length) return false;
                    const offset = this.annotationOffset(annotation);

                    spans.forEach((span) => {
                        const origin = Array.isArray(span?.origin) ? span.origin : null;
                        if (!origin || origin.length < 2) return;
                        const fontFamily = fallbackFontFamily(span?.embedded_font_name || span?.font, span?.embedded_font_family || span?.fontFamily || '');
                        const fontSizePx = (Number(span?.font_size ?? span?.fontSize) || Number(annotation?.fontSize) || 12) * scale;
                        const fontStyle = span?.fontStyle || (span?.italic ? 'italic' : 'normal');
                        const fontWeight = String(span?.font_weight || span?.fontWeight || (span?.bold ? '700' : '400') || '400');
                        const drawText = String(span?.render_text ?? span?.text ?? '');
                        if (!drawText) return;

                        ctx.font = ctxFont({
                            fontFamily,
                            fontSizePx,
                            fontWeight,
                            fontStyle,
                        });
                        ctx.fillStyle = String(span?.hex_color || annotation?.textColor || '#000000');
                        ctx.textBaseline = 'alphabetic';
                        ctx.fillText(drawText, (Number(origin[0]) + offset.dx) * scale, (Number(origin[1]) + offset.dy) * scale);
                    });

                    return true;
                },

                drawEditedAnnotation(annotation, ctx, scale) {
                    const box = this.resolveAnnBox(annotation);
                    if (!box) return;

                    const currentText = String(this.editedTexts[annotation._uid] ?? annotation.text ?? '');
                    const originalText = String(annotation.text || '');

                    if (currentText === originalText && this.drawOriginalSource(annotation, ctx, scale)) {
                        return;
                    }

                    const lines = this.buildEditedLines(annotation, currentText, scale);
                    lines.forEach((line) => {
                        ctx.font = ctxFont(line.style);
                        ctx.fillStyle = line.style.fillStyle;
                        ctx.textBaseline = 'alphabetic';
                        ctx.fillText(line.text, line.xPts * scale, line.baselinePts * scale);
                    });
                },

                redrawOverlay() {
                    const canvas = this.$refs.overlayCanvas;
                    if (!canvas) return;
                    const ctx = canvas.getContext('2d');
                    if (!ctx) return;

                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    const scale = this.scale;

                    this.pageTextAnnotations.forEach((annotation) => {
                        if (annotation._uid === this.activeUid) {
                            return;
                        }
                        this.drawEditedAnnotation(annotation, ctx, scale);
                    });

                    const hover = this.pageTextAnnotations.find((annotation) => annotation._uid === this.hoverUid) || null;
                    if (hover && hover._uid !== this.activeUid) {
                        const rect = this.annotationRectCss(hover);
                        if (rect) {
                            ctx.save();
                            ctx.fillStyle = 'rgba(37, 99, 235, 0.10)';
                            ctx.strokeStyle = 'rgba(37, 99, 235, 0.55)';
                            ctx.lineWidth = 1;
                            ctx.setLineDash([4, 4]);
                            ctx.fillRect(rect.left, rect.top, rect.width, rect.height);
                            ctx.strokeRect(rect.left, rect.top, rect.width, rect.height);
                            ctx.restore();
                        }
                    }

                    const active = this.activeAnnotation;
                    if (active && (Number(active.pageIndex) || 0) === this.currentPage - 1) {
                        const rect = this.annotationRectCss(active);
                        if (rect) {
                            ctx.save();
                            ctx.strokeStyle = '#2563eb';
                            ctx.lineWidth = 1.5;
                            ctx.setLineDash([6, 4]);
                            ctx.fillStyle = 'rgba(37, 99, 235, 0.08)';
                            ctx.fillRect(rect.left, rect.top, rect.width, rect.height);
                            ctx.strokeRect(rect.left, rect.top, rect.width, rect.height);
                            ctx.restore();
                        }
                    }
                },
            };
        }
        </script>
    @endpush
@endonce
</x-filament-panels::page>
