<x-filament-panels::page>
    <div x-data="pdfComparisonPage()" class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold text-gray-900 dark:text-white">PDF Comparison</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Compare the original PDF with the visual snapshot reconstructed from the /edit-new load path.
                </p>
            </div>
            <a href="{{ route('filament.admin.pages.run-pdf-tests') }}">
                <x-filament::button color="gray" size="sm" icon="heroicon-o-arrow-left">PDF Tests</x-filament::button>
            </a>
        </div>

        <div class="overflow-hidden rounded-2xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-white/10">
                <form class="space-y-4" x-on:submit.prevent="runPdfComparison()">
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Document ID</span>
                        <input type="number" min="1" x-model="comparisonDocumentId"
                               class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                               placeholder="2880">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">DPI</span>
                        <input type="number" min="72" max="240" x-model.number="comparisonDpi"
                               class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Tolerance %</span>
                        <input type="number" min="0" max="100" step="0.05" x-model.number="comparisonTolerancePct"
                               class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Max pages</span>
                        <input type="number" min="1" max="50" x-model.number="comparisonMaxPages"
                               class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                               placeholder="All">
                    </label>
                    </div>
                    <div class="flex items-center justify-between gap-3 rounded-xl bg-gray-50 px-4 py-3 dark:bg-gray-950/50">
                        <div>
                            <div class="text-sm font-semibold text-gray-900 dark:text-white">Start comparison</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Renders original, edit-new snapshot, and a highlighted diff for each page.</div>
                        </div>
                        <button type="submit"
                                x-bind:disabled="comparisonRunning || !comparisonDocumentId"
                                class="inline-flex min-w-[170px] items-center justify-center gap-1.5 rounded-lg bg-danger-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-danger-700 disabled:cursor-not-allowed disabled:opacity-50">
                            <svg x-show="comparisonRunning" class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            <svg x-show="!comparisonRunning" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4v16h16"/><path d="M8 16l3-3 2 2 5-6"/></svg>
                            <span x-text="comparisonRunning ? 'Comparing...' : 'Start Comparison'"></span>
                        </button>
                    </div>
                </form>
            </div>

            <template x-if="comparisonError">
                <div class="mx-5 mt-4 rounded-xl border border-danger-200 bg-danger-50 px-4 py-3 dark:border-danger-800 dark:bg-danger-950/20">
                    <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-danger-600 dark:text-danger-400">Comparison error</div>
                    <div class="break-all font-mono text-sm text-danger-700 dark:text-danger-300" x-text="comparisonError"></div>
                </div>
            </template>

            <template x-if="comparisonRunning">
                <div class="flex min-h-[220px] flex-col items-center justify-center gap-3 p-8 text-center">
                    <svg class="h-8 w-8 animate-spin text-danger-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Rendering the edit-new snapshot and diff images.</div>
                </div>
            </template>

            <template x-if="!comparisonRunning && !comparisonResult && !comparisonError">
                <div class="flex min-h-[220px] flex-col items-center justify-center gap-3 p-8 text-center">
                    <svg class="h-10 w-10 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M4 4v16h16"/><path d="M8 16l3-3 2 2 5-6"/></svg>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Enter a document ID to compare what edit-new loads against the original PDF.</div>
                </div>
            </template>

            <template x-if="comparisonResult">
                <div class="space-y-5 p-5">
                    <div class="grid grid-cols-2 gap-3 md:grid-cols-6">
                        <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-950/50">
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Status</div>
                            <div class="mt-1 text-sm font-bold"
                                 x-bind:class="comparisonResult.status === 'pass' ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400'"
                                 x-text="comparisonResult.status === 'pass' ? 'Looks aligned' : 'Needs review'"></div>
                        </div>
                        <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-950/50">
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Overall diff</div>
                            <div class="mt-1 text-sm font-bold text-gray-900 dark:text-white" x-text="formatPercent(comparisonResult.overall?.pixel_diff_pct)"></div>
                        </div>
                        <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-950/50">
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Worst page</div>
                            <div class="mt-1 text-sm font-bold text-gray-900 dark:text-white" x-text="comparisonResult.overall?.worst_page || '-'"></div>
                        </div>
                        <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-950/50">
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Pages</div>
                            <div class="mt-1 text-sm font-bold text-gray-900 dark:text-white"
                                 x-text="comparisonResult.page_count_compared + ' / ' + comparisonResult.page_count_original"></div>
                        </div>
                        <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-950/50">
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Annotations</div>
                            <div class="mt-1 text-sm font-bold text-gray-900 dark:text-white" x-text="comparisonResult.annotation_count"></div>
                        </div>
                        <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-950/50">
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Base</div>
                            <div class="mt-1 text-sm font-bold text-gray-900 dark:text-white" x-text="comparisonResult.loaded_base"></div>
                        </div>
                    </div>

                    <div class="overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-950/40">
                        <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                            <div class="text-sm font-semibold text-gray-900 dark:text-white">AI</div>
                            <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                Copy-ready diagnostics for AI bots, including page, annotation, offset, and suspected issue.
                            </div>
                        </div>
                        <template x-if="comparisonAiFindings().length">
                            <div class="divide-y divide-gray-200 dark:divide-gray-800">
                                <template x-for="finding in comparisonAiFindings()" :key="'ai-' + finding.page + '-' + finding.annotation_id + '-' + finding.pixel_diff_pct">
                                    <div class="p-4">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="rounded-full bg-gray-200 px-2.5 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300" x-text="'Page ' + finding.page"></span>
                                            <span class="rounded-full bg-danger-50 px-2.5 py-1 text-xs font-semibold text-danger-700 dark:bg-danger-950/30 dark:text-danger-400" x-text="'annotation_id=' + finding.annotation_id"></span>
                                            <span class="rounded-full bg-warning-50 px-2.5 py-1 text-xs font-semibold text-warning-700 dark:bg-warning-950/30 dark:text-warning-400" x-text="'diff ' + formatPercent(finding.pixel_diff_pct)"></span>
                                        </div>
                                        <p class="mt-3 text-sm leading-6 text-gray-800 dark:text-gray-200" x-text="finding.description"></p>
                                        <div class="mt-2 grid grid-cols-1 gap-2 text-xs text-gray-500 dark:text-gray-400 md:grid-cols-3">
                                            <div x-text="'Offset: x=' + signedNumber(finding.offset_px?.x) + 'px (' + signedNumber(finding.offset_pct?.x) + '%), y=' + signedNumber(finding.offset_px?.y) + 'px (' + signedNumber(finding.offset_pct?.y) + '%)'"></div>
                                            <div x-text="'Pixels: missing=' + formatInteger(finding.missing_pixels) + ', added=' + formatInteger(finding.added_pixels) + ', changed=' + formatInteger(finding.changed_pixels)"></div>
                                            <div x-text="'Suspected: ' + finding.suspected_issue"></div>
                                        </div>
                                        <template x-if="finding.overlapping_duplicate_annotations && finding.overlapping_duplicate_annotations.length">
                                            <div class="mt-3 rounded-lg bg-white px-3 py-2 text-xs text-gray-600 ring-1 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800">
                                                <div class="font-semibold text-gray-800 dark:text-gray-100">Possible duplicate annotations</div>
                                                <template x-for="duplicate in finding.overlapping_duplicate_annotations" :key="'dup-' + finding.annotation_id + '-' + duplicate.annotation_id">
                                                    <div class="mt-1" x-text="'annotation_id=' + duplicate.annotation_id + ' overlap=' + formatPercent((duplicate.overlap_ratio || 0) * 100) + (duplicate.text ? ' text=' + duplicate.text : '')"></div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <template x-if="!comparisonAiFindings().length">
                            <div class="p-4 text-sm text-gray-500 dark:text-gray-400">
                                No annotation-level issues exceeded the AI diagnostic threshold for the compared pages.
                            </div>
                        </template>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <template x-for="page in comparisonResult.pages" :key="'cmp-page-' + page.page">
                            <button type="button"
                                    x-on:click="comparisonSelectedPage = page.page"
                                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors"
                                    x-bind:class="comparisonSelectedPage === page.page
                                        ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900'
                                        : (page.needs_attention ? 'bg-danger-50 text-danger-700 dark:bg-danger-950/30 dark:text-danger-400' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300')">
                                <span x-text="'Page ' + page.page"></span>
                                <span x-text="formatPercent(page.pixel_diff_pct)"></span>
                            </button>
                        </template>
                    </div>

                    <template x-if="comparisonSelectedPageData()">
                        <div class="space-y-3">
                            <div class="flex flex-wrap items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                                <span x-text="'Page ' + comparisonSelectedPageData().page"></span>
                                <span x-text="'Diff ' + formatPercent(comparisonSelectedPageData().pixel_diff_pct)"></span>
                                <span x-text="'Missing-like pixels ' + formatInteger(comparisonSelectedPageData().missing_pixels)"></span>
                                <span x-text="'Added-like pixels ' + formatInteger(comparisonSelectedPageData().added_pixels)"></span>
                            </div>
                            <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                                <button type="button" class="overflow-hidden rounded-xl border border-gray-200 bg-gray-50 text-left dark:border-gray-700 dark:bg-gray-950/40"
                                        x-on:click="openComparisonArtifact('Original', comparisonSelectedPageData().original_artifact_url)">
                                    <div class="border-b border-gray-200 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">Original</div>
                                    <img :src="comparisonSelectedPageData().original_artifact_url" class="max-h-[520px] w-full bg-white object-contain dark:bg-gray-950" loading="lazy">
                                </button>
                                <button type="button" class="overflow-hidden rounded-xl border border-gray-200 bg-gray-50 text-left dark:border-gray-700 dark:bg-gray-950/40"
                                        x-on:click="openComparisonArtifact('Edit-new snapshot', comparisonSelectedPageData().snapshot_artifact_url)">
                                    <div class="border-b border-gray-200 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">Edit-new snapshot</div>
                                    <img :src="comparisonSelectedPageData().snapshot_artifact_url" class="max-h-[520px] w-full bg-white object-contain dark:bg-gray-950" loading="lazy">
                                </button>
                                <button type="button" class="overflow-hidden rounded-xl border border-gray-200 bg-gray-50 text-left dark:border-gray-700 dark:bg-gray-950/40"
                                        x-on:click="openComparisonArtifact('Difference', comparisonSelectedPageData().diff_artifact_url)">
                                    <div class="border-b border-gray-200 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">Difference</div>
                                    <img :src="comparisonSelectedPageData().diff_artifact_url" class="max-h-[520px] w-full bg-white object-contain dark:bg-gray-950" loading="lazy">
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </template>
        </div>

        <div x-show="activeArtifact" x-cloak
             class="fixed inset-0 z-[100] flex items-center justify-center bg-black/80 p-6"
             x-on:click.self="closeArtifact()"
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100">
            <div class="w-full max-w-6xl overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-gray-900">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                    <div class="text-sm font-medium text-gray-900 dark:text-white" x-text="activeArtifact?.label || ''"></div>
                    <button type="button" x-on:click="closeArtifact()"
                            class="rounded-lg px-3 py-1.5 text-sm text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white">
                        Close
                    </button>
                </div>
                <div class="bg-gray-100 p-4 dark:bg-gray-950">
                    <img x-show="activeArtifact"
                         :src="activeArtifact?.url"
                         :alt="activeArtifact?.label || ''"
                         class="max-h-[80vh] w-full object-contain">
                </div>
            </div>
        </div>
    </div>

    <style>
        [x-cloak] { display: none !important; }
    </style>

    <script>
        function pdfComparisonPage() {
            return {
                comparisonDocumentId: '',
                comparisonDpi: 144,
                comparisonTolerancePct: 0.25,
                comparisonMaxPages: '',
                comparisonRunning: false,
                comparisonResult: null,
                comparisonError: '',
                comparisonSelectedPage: null,
                activeArtifact: null,

                async runPdfComparison() {
                    const documentId = String(this.comparisonDocumentId || '').trim();
                    if (!documentId || this.comparisonRunning) return;
                    this.comparisonRunning = true;
                    this.comparisonError = '';
                    this.comparisonResult = null;
                    this.comparisonSelectedPage = null;
                    try {
                        const baseUrl = '{{ route('pdfTests.compareEditNewSnapshot', ['document' => '__DOCUMENT_ID__']) }}';
                        const url = baseUrl.replace('__DOCUMENT_ID__', encodeURIComponent(documentId));
                        const payload = {
                            dpi: Number(this.comparisonDpi || 144),
                            threshold: 10,
                            tolerance_pct: Number(this.comparisonTolerancePct || 0.25),
                        };
                        if (this.comparisonMaxPages) {
                            payload.max_pages = Number(this.comparisonMaxPages);
                        }

                        const response = await fetch(url, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                            },
                            body: JSON.stringify(payload),
                        });
                        const data = await response.json();
                        if (!response.ok || !data.success) throw new Error(data.message || 'Comparison failed');
                        this.comparisonResult = data;
                        this.comparisonSelectedPage = data.overall?.worst_page || data.pages?.[0]?.page || null;
                    } catch (error) {
                        this.comparisonError = error?.message || String(error);
                    } finally {
                        this.comparisonRunning = false;
                    }
                },

                comparisonSelectedPageData() {
                    if (!this.comparisonResult || !this.comparisonSelectedPage) return null;
                    return (this.comparisonResult.pages || []).find((page) => page.page === this.comparisonSelectedPage) || null;
                },

                comparisonAiFindings() {
                    if (!this.comparisonResult) return [];
                    if (Array.isArray(this.comparisonResult.ai_findings)) return this.comparisonResult.ai_findings;
                    return (this.comparisonResult.pages || []).flatMap((page) => Array.isArray(page.ai_findings) ? page.ai_findings : []);
                },

                openComparisonArtifact(label, url) {
                    if (!url) return;
                    this.activeArtifact = { label, url };
                },

                closeArtifact() {
                    this.activeArtifact = null;
                },

                formatPercent(value) {
                    const n = Number(value);
                    if (!Number.isFinite(n)) return '-';
                    return `${n.toFixed(n >= 10 ? 1 : 3)}%`;
                },

                formatInteger(value) {
                    const n = Number(value);
                    if (!Number.isFinite(n)) return '0';
                    return new Intl.NumberFormat().format(n);
                },

                signedNumber(value) {
                    const n = Number(value);
                    if (!Number.isFinite(n)) return '0';
                    return `${n >= 0 ? '+' : ''}${n.toFixed(2)}`;
                },
            };
        }
    </script>
</x-filament-panels::page>
