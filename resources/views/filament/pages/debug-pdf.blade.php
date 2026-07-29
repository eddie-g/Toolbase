<x-filament-panels::page>
    <div x-data="pdfAnnotationDebugPage()" class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold text-gray-900 dark:text-white">Debug promoted PDF annotations</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Load a document editor in the background, run the same annotation builders, and inspect the matching block output.
                </p>
            </div>
            <a href="{{ route('filament.admin.pages.run-pdf-tests') }}">
                <x-filament::button color="gray" size="sm" icon="heroicon-o-arrow-left">PDF Tests</x-filament::button>
            </a>
        </div>

        <div class="rounded-2xl bg-white p-6 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <form class="grid grid-cols-1 gap-4 lg:grid-cols-[220px_minmax(0,1fr)_auto]" x-on:submit.prevent="inspect()">
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Document ID</span>
                    <input
                        type="number"
                        min="1"
                        inputmode="numeric"
                        x-model.trim="documentId"
                        class="block w-full rounded-xl border-0 bg-gray-50 px-4 py-3 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-primary-500 dark:bg-gray-950 dark:text-white dark:ring-white/10"
                        placeholder="1775"
                    >
                </label>

                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Match string</span>
                    <input
                        type="text"
                        x-model.trim="query"
                        class="block w-full rounded-xl border-0 bg-gray-50 px-4 py-3 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-primary-500 dark:bg-gray-950 dark:text-white dark:ring-white/10"
                        placeholder="promoted_1_11 or block-1-11"
                    >
                </label>

                <div class="flex items-end gap-3">
                    <x-filament::button type="submit" color="danger" x-bind:disabled="loading">
                        <span x-show="!loading">Inspect</span>
                        <span x-show="loading" x-cloak>Inspecting...</span>
                    </x-filament::button>
                    <template x-if="editorUrl">
                        <a x-bind:href="editorUrl" target="_blank" rel="noopener noreferrer">
                            <x-filament::button type="button" color="gray">Open Editor</x-filament::button>
                        </a>
                    </template>
                </div>
            </form>

            <div class="mt-4 flex flex-wrap items-center gap-3 text-xs">
                <template x-if="statusText">
                    <span
                        class="inline-flex items-center rounded-full px-3 py-1 font-medium"
                        x-bind:class="statusTone === 'error'
                            ? 'bg-danger-50 text-danger-700 dark:bg-danger-950/30 dark:text-danger-400'
                            : (statusTone === 'warn'
                                ? 'bg-warning-50 text-warning-700 dark:bg-warning-950/30 dark:text-warning-400'
                                : 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-300')"
                        x-text="statusText"
                    ></span>
                </template>
                <template x-if="result">
                    <span class="text-gray-500 dark:text-gray-400" x-text="result.annotationMatchesCount + ' annotation match(es)'"></span>
                </template>
                <template x-if="result">
                    <span class="text-gray-500 dark:text-gray-400" x-text="result.fitzMatchesCount + ' Fitz match(es)'"></span>
                </template>
                <template x-if="result && result.truncated">
                    <span class="text-warning-600 dark:text-warning-400">Results truncated at the page-side limit.</span>
                </template>
            </div>
        </div>

        <template x-if="result">
            <div class="grid grid-cols-1 gap-4">
                <template x-if="result.annotationMatchesCount > 0">
                    <div class="overflow-hidden rounded-2xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <div class="border-b border-gray-100 px-5 py-4 dark:border-white/10">
                            <div class="text-sm font-semibold text-gray-900 dark:text-white">Annotation matches</div>
                        </div>

                        <div class="divide-y divide-gray-100 dark:divide-white/10">
                            <template x-for="match in result.annotationMatches" :key="'annotation-' + (match.id || '') + '-' + match.pageIndex">
                                <div class="p-5">
                                    <div class="flex flex-wrap items-center gap-3">
                                        <div class="text-sm font-semibold text-gray-900 dark:text-white" x-text="(match.type || 'text') + ' · page ' + (match.pageIndex + 1)"></div>
                                        <template x-if="match.id">
                                            <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-white/5 dark:text-gray-300" x-text="match.id"></span>
                                        </template>
                                        <template x-if="match.key">
                                            <span class="rounded-full bg-danger-50 px-2.5 py-1 text-xs font-medium text-danger-700 dark:bg-danger-950/30 dark:text-danger-400" x-text="match.key"></span>
                                        </template>
                                        <template x-for="reason in match.matchReasons" :key="reason">
                                            <span class="rounded-full bg-warning-50 px-2.5 py-1 text-xs font-medium text-warning-700 dark:bg-warning-950/30 dark:text-warning-400" x-text="reason"></span>
                                        </template>
                                    </div>

                                    <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
                                        <div>
                                            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Annotation</div>
                                            <pre class="max-h-72 overflow-auto rounded-xl bg-gray-50 p-4 text-xs leading-5 text-black ring-1 ring-inset ring-gray-200 dark:bg-white dark:text-black dark:ring-gray-300" x-text="prettyJson(match)"></pre>
                                        </div>
                                        <div>
                                            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Source Text Lines</div>
                                            <pre class="max-h-72 overflow-auto rounded-xl bg-gray-50 p-4 text-xs leading-5 text-black ring-1 ring-inset ring-gray-200 dark:bg-white dark:text-black dark:ring-gray-300" x-text="prettyJson(match.sourceTextLines)"></pre>
                                        </div>
                                        <div>
                                            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Source Spans</div>
                                            <pre class="max-h-72 overflow-auto rounded-xl bg-gray-50 p-4 text-xs leading-5 text-black ring-1 ring-inset ring-gray-200 dark:bg-white dark:text-black dark:ring-gray-300" x-text="prettyJson(match.sourceSpans)"></pre>
                                        </div>
                                        <div>
                                            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Line Reuse Diagnostics</div>
                                            <pre class="max-h-72 overflow-auto rounded-xl bg-gray-50 p-4 text-xs leading-5 text-black ring-1 ring-inset ring-gray-200 dark:bg-white dark:text-black dark:ring-gray-300" x-text="prettyJson(match.lineReuseDiagnostics)"></pre>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                <template x-if="result.fitzMatchesCount > 0">
                    <div class="overflow-hidden rounded-2xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <div class="border-b border-gray-100 px-5 py-4 dark:border-white/10">
                            <div class="text-sm font-semibold text-gray-900 dark:text-white">Fitz extraction matches</div>
                        </div>

                        <div class="grid grid-cols-1 gap-4 p-5">
                            <template x-for="match in result.fitzMatches" :key="'fitz-' + match.pageNumber + '-' + match.blockNum">
                                <div class="overflow-hidden rounded-2xl border border-gray-100 dark:border-white/10">
                                    <div class="border-b border-gray-100 px-5 py-4 dark:border-white/10">
                                        <div class="flex flex-wrap items-center gap-3">
                                            <div class="text-sm font-semibold text-gray-900 dark:text-white" x-text="'Page ' + match.pageNumber + ' · Block ' + match.blockNum"></div>
                                            <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-white/5 dark:text-gray-300" x-text="match.blockSourceKey"></span>
                                            <template x-for="reason in match.matchReasons" :key="reason">
                                                <span class="rounded-full bg-danger-50 px-2.5 py-1 text-xs font-medium text-danger-700 dark:bg-danger-950/30 dark:text-danger-400" x-text="reason"></span>
                                            </template>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 gap-4 p-5 xl:grid-cols-2">
                                        <div class="space-y-4">
                                            <div>
                                                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Block Text</div>
                                                <pre class="max-h-56 overflow-auto rounded-xl bg-gray-50 p-4 text-xs leading-5 text-black ring-1 ring-inset ring-gray-200 dark:bg-white dark:text-black dark:ring-gray-300" x-text="prettyJson(match.blockText)"></pre>
                                            </div>

                                            <div>
                                                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Raw Text Lines</div>
                                                <pre class="max-h-56 overflow-auto rounded-xl bg-gray-50 p-4 text-xs leading-5 text-black ring-1 ring-inset ring-gray-200 dark:bg-white dark:text-black dark:ring-gray-300" x-text="prettyJson(match.rawTextLines)"></pre>
                                            </div>

                                            <div>
                                                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Trimmed Text Lines</div>
                                                <pre class="max-h-56 overflow-auto rounded-xl bg-gray-50 p-4 text-xs leading-5 text-black ring-1 ring-inset ring-gray-200 dark:bg-white dark:text-black dark:ring-gray-300" x-text="prettyJson(match.trimmedTextLines)"></pre>
                                            </div>

                                            <div>
                                                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Raw Line Reuse Diagnostics</div>
                                                <pre class="max-h-56 overflow-auto rounded-xl bg-gray-50 p-4 text-xs leading-5 text-black ring-1 ring-inset ring-gray-200 dark:bg-white dark:text-black dark:ring-gray-300" x-text="prettyJson(match.raw?.lineReuseDiagnostics || [])"></pre>
                                            </div>

                                            <div>
                                                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Trimmed Line Reuse Diagnostics</div>
                                                <pre class="max-h-56 overflow-auto rounded-xl bg-gray-50 p-4 text-xs leading-5 text-black ring-1 ring-inset ring-gray-200 dark:bg-white dark:text-black dark:ring-gray-300" x-text="prettyJson(match.trimmed?.lineReuseDiagnostics || [])"></pre>
                                            </div>
                                        </div>

                                        <div class="space-y-4">
                                            <div>
                                                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Matching Built Annotations</div>
                                                <pre class="max-h-56 overflow-auto rounded-xl bg-gray-50 p-4 text-xs leading-5 text-black ring-1 ring-inset ring-gray-200 dark:bg-white dark:text-black dark:ring-gray-300" x-text="prettyJson(match.matchingBuilt)"></pre>
                                            </div>

                                            <div>
                                                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Raw Source Spans</div>
                                                <pre class="max-h-56 overflow-auto rounded-xl bg-gray-50 p-4 text-xs leading-5 text-black ring-1 ring-inset ring-gray-200 dark:bg-white dark:text-black dark:ring-gray-300" x-text="prettyJson(match.raw?.sourceSpans || [])"></pre>
                                            </div>

                                            <div>
                                                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Trimmed Source Spans</div>
                                                <pre class="max-h-56 overflow-auto rounded-xl bg-gray-50 p-4 text-xs leading-5 text-black ring-1 ring-inset ring-gray-200 dark:bg-white dark:text-black dark:ring-gray-300" x-text="prettyJson(match.trimmed?.sourceSpans || [])"></pre>
                                            </div>

                                            <details class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                                                <summary class="cursor-pointer text-sm font-medium text-gray-800 dark:text-gray-200">Built</summary>
                                                <pre class="mt-3 max-h-72 overflow-auto rounded-xl bg-gray-50 p-4 text-xs leading-5 text-black ring-1 ring-inset ring-gray-200 dark:bg-white dark:text-black dark:ring-gray-300" x-text="prettyJson(match.built)"></pre>
                                            </details>

                                            <details class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                                                <summary class="cursor-pointer text-sm font-medium text-gray-800 dark:text-gray-200">Raw Source</summary>
                                                <pre class="mt-3 max-h-72 overflow-auto rounded-xl bg-gray-50 p-4 text-xs leading-5 text-black ring-1 ring-inset ring-gray-200 dark:bg-white dark:text-black dark:ring-gray-300" x-text="prettyJson(match.raw)"></pre>
                                            </details>

                                            <details class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                                                <summary class="cursor-pointer text-sm font-medium text-gray-800 dark:text-gray-200">Trimmed Source</summary>
                                                <pre class="mt-3 max-h-72 overflow-auto rounded-xl bg-gray-50 p-4 text-xs leading-5 text-black ring-1 ring-inset ring-gray-200 dark:bg-white dark:text-black dark:ring-gray-300" x-text="prettyJson(match.trimmed)"></pre>
                                            </details>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                <template x-if="result.annotationMatchesCount === 0 && result.fitzMatchesCount === 0">
                    <div class="overflow-hidden rounded-2xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <div class="px-5 py-6 text-sm text-gray-500 dark:text-gray-400">
                            No annotation or Fitz extraction matches for this string.
                        </div>
                    </div>
                </template>
            </div>
        </template>

        <iframe x-ref="editorFrame" class="hidden" title="PDF annotation debug frame"></iframe>
    </div>

    <script>
        function pdfAnnotationDebugPage() {
            return {
                documentId: '',
                query: '',
                loading: false,
                statusText: '',
                statusTone: 'info',
                result: null,
                editorUrl: '',
                loadedDocumentId: null,

                prettyJson(value) {
                    return JSON.stringify(value, null, 2);
                },

                buildEditorUrl(documentId) {
                    const id = encodeURIComponent(String(documentId).trim());
                    return `{{ url('/documents') }}/${id}/edit?pdf_debug_embed=1`;
                },

                setStatus(message, tone = 'info') {
                    this.statusText = message;
                    this.statusTone = tone;
                },

                async inspect() {
                    const documentId = String(this.documentId || '').trim();
                    const query = String(this.query || '').trim();

                    if (!documentId) {
                        this.setStatus('Enter a document ID.', 'error');
                        return;
                    }
                    if (!query) {
                        this.setStatus('Enter a string to match.', 'error');
                        return;
                    }

                    this.loading = true;
                    this.result = null;
                    this.setStatus('Loading editor context…');

                    try {
                        const api = await this.ensureDebugApi(documentId);
                        this.setStatus('Running promoted annotation inspection…');
                        this.result = await api.inspectPromotedAnnotationMatches(query);

                        if (!this.result.totalMatches) {
                            this.setStatus('No annotation or Fitz matches were found.', 'warn');
                        } else {
                            this.setStatus(`Loaded ${this.result.annotationMatchesCount} annotation match(es) and ${this.result.fitzMatchesCount} Fitz match(es).`);
                        }
                    } catch (error) {
                        console.error('[PDF Debug] Inspection failed', error);
                        // The editor frame may contain stale or partially initialized
                        // runtime code. Force the next inspection to reload it.
                        this.loadedDocumentId = null;
                        this.setStatus(error?.message || 'Inspection failed.', 'error');
                    } finally {
                        this.loading = false;
                    }
                },

                async ensureDebugApi(documentId) {
                    const frame = this.$refs.editorFrame;
                    const nextUrl = this.buildEditorUrl(documentId);
                    this.editorUrl = nextUrl;

                    if (String(this.loadedDocumentId) !== String(documentId)) {
                        await this.loadFrame(frame, nextUrl);
                        this.loadedDocumentId = String(documentId);
                    }

                    return this.waitForDebugApi(frame.contentWindow);
                },

                loadFrame(frame, url) {
                    return new Promise((resolve, reject) => {
                        let settled = false;

                        const cleanup = () => {
                            frame.removeEventListener('load', handleLoad);
                            frame.removeEventListener('error', handleError);
                        };

                        const handleLoad = () => {
                            if (settled) return;
                            settled = true;
                            cleanup();
                            resolve();
                        };

                        const handleError = () => {
                            if (settled) return;
                            settled = true;
                            cleanup();
                            reject(new Error('Failed to load the document editor.'));
                        };

                        frame.addEventListener('load', handleLoad, { once: true });
                        frame.addEventListener('error', handleError, { once: true });
                        frame.src = url;
                    });
                },

                waitForDebugApi(targetWindow, timeoutMs = 120000) {
                    return new Promise((resolve, reject) => {
                        const startedAt = Date.now();

                        const poll = () => {
                            try {
                                const api = targetWindow?.pdfPromotedAnnotationDebugApi;
                                if (api && typeof api.inspectPromotedAnnotationMatches === 'function') {
                                    resolve(api);
                                    return;
                                }
                            } catch (error) {
                                reject(error);
                                return;
                            }

                            if (Date.now() - startedAt >= timeoutMs) {
                                reject(new Error('Timed out waiting for the document debug API.'));
                                return;
                            }

                            window.setTimeout(poll, 250);
                        };

                        poll();
                    });
                },
            };
        }
    </script>
</x-filament-panels::page>
