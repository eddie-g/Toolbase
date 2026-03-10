<x-filament-panels::page>
    <div x-data="pdfTestRunner()" x-init="init()" class="space-y-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <x-filament::button
                    x-on:click="startTests()"
                    x-bind:disabled="running"
                    icon="heroicon-o-play"
                    color="danger"
                >
                    <span x-show="!running">Start PDF Tests</span>
                    <span x-show="running" x-cloak>Running...</span>
                </x-filament::button>

                <a href="{{ \App\Filament\Resources\OverlayEditorTestResource::getUrl() }}">
                    <x-filament::button color="gray" icon="heroicon-o-arrow-left">
                        Back to Results
                    </x-filament::button>
                </a>
            </div>

            <div class="text-sm text-gray-500 dark:text-gray-400" x-show="availableFilesTotal > 0" x-cloak>
                <template x-if="running || completedCount > 0">
                    <span><span x-text="completedCount"></span> / <span x-text="totalFiles"></span> tests</span>
                </template>
                <template x-if="!running && completedCount === 0">
                    <span x-text="availableFilesTotal + ' available tests'"></span>
                </template>
            </div>
        </div>

        <div class="bg-danger-50 dark:bg-danger-950/20 border border-danger-200 dark:border-danger-800 rounded-xl p-4">
            <div class="flex items-start gap-3">
                <x-heroicon-o-document-text class="h-5 w-5 text-danger-500 mt-0.5 flex-shrink-0" />
                <div>
                    <div class="font-medium text-danger-700 dark:text-danger-300">PDF Flow Tests</div>
                    <div class="text-sm text-danger-600 dark:text-danger-400 mt-1">
                        `Test 1 : Text Position` creates a fresh blank PDF, adds centered text, moves it, and confirms the saved position. `Test 2 : Text Styling` creates a fresh blank PDF, changes the text through the selection-toolbar, saves it, and confirms the saved style matches. `Test 3 : Paragraphs` creates a lorem ipsum paragraph, resizes it between narrow and wide boxes to confirm reflow, moves it while preserving layout, then deletes it and confirms it is gone. The screenshots below come from those browser runs.
                    </div>
                </div>
            </div>
        </div>

        <div x-show="loadedFromDatabase && results.length > 0" x-cloak
             class="bg-primary-50 dark:bg-primary-950/20 border border-primary-200 dark:border-primary-800 rounded-xl p-4">
            <div class="flex items-start gap-3">
                <x-heroicon-o-clock class="h-5 w-5 text-primary-500 mt-0.5 flex-shrink-0" />
                <div>
                    <div class="font-medium text-primary-700 dark:text-primary-300">Latest saved PDF run loaded</div>
                    <div class="text-sm text-primary-600 dark:text-primary-400 mt-1">
                        Showing the most recent saved PDF test results from
                        <span class="font-medium" x-text="formatTimestamp(latestRunCreatedAt)"></span>.
                    </div>
                </div>
            </div>
        </div>

        <div x-show="files.length > 0" x-cloak class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="font-semibold text-gray-900 dark:text-white">Available PDF Tests</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Run the full suite or execute either PDF flow independently. The latest saved run is shown here when available.
                    </div>
                </div>
                <div class="text-sm text-gray-500 dark:text-gray-400" x-text="availableFilesTotal + ' total'"></div>
            </div>

            <div class="mt-4 space-y-3">
                <template x-for="file in files" :key="file.path">
                    <div class="rounded-xl border border-gray-200 dark:border-gray-700 px-4 py-4 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <div class="font-medium text-gray-900 dark:text-white" x-text="file.section_name || file.filename"></div>
                                <template x-if="latestResultFor(file.path)">
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold uppercase"
                                          x-bind:class="statusBadgeClasses(latestResultFor(file.path)?.status)"
                                          x-text="latestResultFor(file.path)?.status">
                                    </span>
                                </template>
                            </div>

                            <div class="text-sm text-gray-500 dark:text-gray-400 mt-1" x-text="file.description || ''"></div>

                            <template x-if="latestResultFor(file.path)">
                                <div class="text-xs text-gray-500 dark:text-gray-400 mt-2"
                                     x-text="'Last saved result: ' + formatTimestamp(latestResultFor(file.path)?.created_at || latestRunCreatedAt)">
                                </div>
                            </template>
                        </div>

                        <x-filament::button
                            color="gray"
                            size="sm"
                            class="lg:flex-shrink-0"
                            x-on:click="startSingleTest(file)"
                            x-bind:disabled="running"
                        >
                            <span x-show="!(running && runningTestKey === file.path)">Run This Test</span>
                            <span x-show="running && runningTestKey === file.path" x-cloak>Running...</span>
                        </x-filament::button>
                    </div>
                </template>
            </div>
        </div>

        <div x-show="running || completedCount > 0" x-cloak>
            <div class="relative w-full bg-gray-200 dark:bg-gray-700 rounded-full h-4 overflow-hidden">
                <div
                    class="h-full rounded-full transition-all duration-300 ease-out"
                    x-bind:class="{
                        'bg-danger-500': running,
                        'bg-success-500': !running && failedCount === 0 && errorCount === 0,
                        'bg-warning-500': !running && (failedCount > 0 || errorCount > 0)
                    }"
                    x-bind:style="'width: ' + progressPercent + '%'"
                ></div>
                <div class="absolute inset-0 flex items-center justify-center text-xs font-semibold"
                     x-bind:class="progressPercent > 50 ? 'text-white' : 'text-gray-700 dark:text-gray-300'">
                    <span x-text="progressPercent + '%'"></span>
                </div>
            </div>
        </div>

        <div x-show="completedCount > 0" x-cloak class="grid grid-cols-4 gap-4">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Total</div>
                <div class="text-2xl font-bold text-gray-900 dark:text-white" x-text="completedCount"></div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Passed</div>
                <div class="text-2xl font-bold text-success-600" x-text="passedCount"></div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Failed</div>
                <div class="text-2xl font-bold text-danger-600" x-text="failedCount"></div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Errors</div>
                <div class="text-2xl font-bold text-warning-600" x-text="errorCount"></div>
            </div>
        </div>

        <div x-show="running && currentTest" x-cloak
             class="bg-danger-50 dark:bg-danger-950/20 border border-danger-200 dark:border-danger-800 rounded-xl p-4 flex items-center gap-3">
            <svg class="animate-spin h-5 w-5 text-danger-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <div>
                <div class="font-medium text-danger-700 dark:text-danger-300" x-text="'Running: ' + currentTest"></div>
                <div class="text-sm text-danger-600 dark:text-danger-400" x-text="currentDescription"></div>
            </div>
        </div>

        <div x-show="results.length > 0" x-cloak class="space-y-4">
            <template x-for="(r, idx) in results" :key="idx">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
                    <div class="px-4 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-3">
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold uppercase"
                                      x-bind:class="{
                                          'bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400': r.status === 'pass',
                                          'bg-danger-100 text-danger-700 dark:bg-danger-900/30 dark:text-danger-400': r.status === 'fail',
                                          'bg-warning-100 text-warning-700 dark:bg-warning-900/30 dark:text-warning-400': r.status === 'error'
                                      }"
                                      x-text="r.status">
                                </span>
                                <div class="font-semibold text-gray-900 dark:text-white" x-text="r.section_name || r.filename"></div>
                            </div>
                            <div class="text-sm text-gray-500 dark:text-gray-400 mt-1" x-text="r.description"></div>
                        </div>
                        <div class="text-sm text-right">
                            <div class="font-semibold text-gray-900 dark:text-white" x-text="r.checks_passed + '/' + r.checks_total"></div>
                            <div class="text-gray-500 dark:text-gray-400">checks passed</div>
                        </div>
                    </div>

                    <div class="p-4 space-y-4">
                        <template x-if="r.artifacts && r.artifacts.length">
                            <div>
                                <div class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Browser Screenshots</div>
                                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                                    <template x-for="(artifact, artifactIndex) in r.artifacts" :key="artifactIndex">
                                        <div class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden bg-gray-50 dark:bg-gray-900/40">
                                            <template x-if="artifact.kind === 'image'">
                                                <button type="button" class="block w-full text-left" x-on:click="openArtifact(artifact)">
                                                    <img :src="artifact.url" :alt="artifact.label" class="w-full h-56 object-contain bg-white dark:bg-gray-950" loading="lazy">
                                                </button>
                                            </template>
                                            <template x-if="artifact.kind !== 'image'">
                                                <div class="p-4">
                                                    <a :href="artifact.url" target="_blank" class="text-sm text-primary-600 dark:text-primary-400 underline" x-text="artifact.label"></a>
                                                </div>
                                            </template>
                                            <div class="px-3 py-2 text-xs font-medium text-gray-600 dark:text-gray-300 border-t border-gray-200 dark:border-gray-700">
                                                <span x-text="artifact.label"></span>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <div>
                            <div class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Checks</div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50 dark:bg-gray-700/50">
                                        <tr>
                                            <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Result</th>
                                            <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Check</th>
                                            <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Description</th>
                                            <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Detail</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                        <template x-for="(check, ci) in (r.checks || [])" :key="ci">
                                            <tr>
                                                <td class="px-3 py-2">
                                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold"
                                                          x-bind:class="{
                                                              'bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400': check.result === 'PASS',
                                                              'bg-danger-100 text-danger-700 dark:bg-danger-900/30 dark:text-danger-400': check.result !== 'PASS'
                                                          }"
                                                          x-text="check.result">
                                                    </span>
                                                </td>
                                                <td class="px-3 py-2 font-medium text-gray-700 dark:text-gray-300" x-text="check.item"></td>
                                                <td class="px-3 py-2 text-gray-600 dark:text-gray-400" x-text="check.description"></td>
                                                <td class="px-3 py-2 text-xs text-gray-500 dark:text-gray-500" x-text="check.detail"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <template x-if="r.error">
                            <div class="rounded-lg bg-danger-50 dark:bg-danger-950/20 border border-danger-200 dark:border-danger-800 px-4 py-3 text-sm text-danger-700 dark:text-danger-300" x-text="r.error"></div>
                        </template>

                        <template x-if="r.warnings && r.warnings.length">
                            <div class="rounded-lg bg-warning-50 dark:bg-warning-950/20 border border-warning-200 dark:border-warning-800 px-4 py-3">
                                <div class="text-sm font-medium text-warning-700 dark:text-warning-300 mb-2">Warnings</div>
                                <ul class="space-y-1 text-sm text-warning-700 dark:text-warning-300">
                                    <template x-for="(warning, warningIndex) in r.warnings" :key="warningIndex">
                                        <li x-text="warning"></li>
                                    </template>
                                </ul>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        <div x-show="!running && results.length === 0 && !loading" class="text-center py-12">
            <x-heroicon-o-document-text class="mx-auto h-12 w-12 text-gray-400" />
            <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No PDF Tests Run</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Start the PDF tests to create fresh blank PDFs, run the text-position, text-styling, and paragraph-flow save sequences, and render the browser screenshots here.
            </p>
        </div>

        <div x-show="loading" x-cloak class="text-center py-12">
            <svg class="animate-spin mx-auto h-8 w-8 text-danger-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Loading PDF tests...</p>
        </div>

        <div x-show="finished && !running" x-cloak x-transition
             class="rounded-xl p-4"
             x-bind:class="failedCount === 0 && errorCount === 0
                 ? 'bg-success-50 dark:bg-success-950/20 border border-success-200 dark:border-success-800'
                 : 'bg-danger-50 dark:bg-danger-950/20 border border-danger-200 dark:border-danger-800'">
            <div class="flex items-center gap-3">
                <template x-if="failedCount === 0 && errorCount === 0">
                    <x-heroicon-o-check-circle class="h-6 w-6 text-success-500" />
                </template>
                <template x-if="failedCount > 0 || errorCount > 0">
                    <x-heroicon-o-exclamation-triangle class="h-6 w-6 text-danger-500" />
                </template>
                <div>
                    <div class="font-semibold"
                         x-bind:class="failedCount === 0 && errorCount === 0 ? 'text-success-700 dark:text-success-300' : 'text-danger-700 dark:text-danger-300'"
                         x-text="'PDF Tests Complete — ' + passedCount + ' passed, ' + failedCount + ' failed, ' + errorCount + ' errors out of ' + completedCount + ' tests'">
                    </div>
                    <div class="text-sm mt-1"
                         x-bind:class="failedCount === 0 && errorCount === 0 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400'">
                        Latest results are shown below. <a href="{{ \App\Filament\Resources\OverlayEditorTestResource::getUrl() }}" class="underline">View in Test Results</a>
                    </div>
                </div>
            </div>
        </div>

        <div x-show="activeArtifact" x-cloak class="fixed inset-0 z-[100] bg-black/80 p-6 flex items-center justify-center" x-on:click.self="closeArtifact()">
            <div class="max-w-6xl w-full bg-white dark:bg-gray-900 rounded-2xl overflow-hidden shadow-2xl">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between">
                    <div class="font-medium text-gray-900 dark:text-white" x-text="activeArtifact?.label || ''"></div>
                    <x-filament::button color="gray" size="sm" x-on:click="closeArtifact()">Close</x-filament::button>
                </div>
                <div class="p-4 bg-gray-100 dark:bg-gray-950">
                    <img x-show="activeArtifact && activeArtifact.kind === 'image'" :src="activeArtifact?.url" :alt="activeArtifact?.label || ''" class="w-full max-h-[80vh] object-contain">
                </div>
            </div>
        </div>
    </div>

    <style>
        [x-cloak] { display: none !important; }
    </style>

    <script>
        function pdfTestRunner() {
            return {
                running: false,
                loading: false,
                loadedFromDatabase: false,
                finished: false,
                files: [],
                results: [],
                availableFilesTotal: 0,
                totalFiles: 0,
                completedCount: 0,
                passedCount: 0,
                failedCount: 0,
                errorCount: 0,
                currentTest: '',
                currentDescription: '',
                runId: null,
                runningTestKey: null,
                latestRunId: null,
                latestRunCreatedAt: null,
                activeArtifact: null,

                get progressPercent() {
                    if (!this.totalFiles) return 0;
                    return Math.round((this.completedCount / this.totalFiles) * 100);
                },

                async init() {
                    this.loading = true;
                    try {
                        const response = await fetch('{{ route('pdfTests.testFiles') }}', {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                        });
                        const data = await response.json();
                        if (!response.ok || !data.success) {
                            throw new Error(data.message || 'Failed to load PDF tests');
                        }
                        this.runId = data.run_id;
                        this.files = data.files || [];
                        this.availableFilesTotal = data.total || this.files.length;

                        if (data.latest_run?.results?.length) {
                            this.loadedFromDatabase = true;
                            this.latestRunId = data.latest_run.run_id || null;
                            this.latestRunCreatedAt = data.latest_run.created_at || null;
                            this.setResults(data.latest_run.results, data.latest_run.total || data.latest_run.results.length);
                        }
                    } catch (error) {
                        console.error(error);
                    } finally {
                        this.loading = false;
                    }
                },

                resetStats() {
                    this.setResults([], 0);
                    this.finished = false;
                    this.loadedFromDatabase = false;
                    this.currentTest = '';
                    this.currentDescription = '';
                    this.runningTestKey = null;
                    this.activeArtifact = null;
                },

                async startTests() {
                    if (this.running || !this.files.length) return;
                    this.running = true;
                    this.resetStats();
                    this.runId = this.nextRunId();
                    this.totalFiles = this.files.length;

                    for (const file of this.files) {
                        this.runningTestKey = file.path;
                        this.currentTest = file.section_name || file.filename;
                        this.currentDescription = file.description || '';
                        this.results.push(await this.runTestRequest(file));
                        this.recalculateStats();
                    }

                    this.running = false;
                    this.finished = true;
                    this.runningTestKey = null;
                    this.completedCount = this.results.length;
                    this.latestRunId = this.runId;
                    this.latestRunCreatedAt = this.results
                        .map((result) => result.created_at)
                        .filter(Boolean)
                        .pop() || new Date().toISOString();
                    this.currentTest = '';
                    this.currentDescription = '';
                },

                async startSingleTest(file) {
                    if (this.running || !file) return;

                    this.running = true;
                    this.resetStats();
                    this.runId = this.nextRunId();
                    this.totalFiles = 1;
                    this.runningTestKey = file.path;
                    this.currentTest = file.section_name || file.filename;
                    this.currentDescription = file.description || '';

                    const result = await this.runTestRequest(file);
                    this.setResults([result], 1);

                    this.running = false;
                    this.finished = true;
                    this.runningTestKey = null;
                    this.latestRunId = this.runId;
                    this.latestRunCreatedAt = result.created_at || new Date().toISOString();
                    this.currentTest = '';
                    this.currentDescription = '';
                },

                setResults(results, total = 0) {
                    this.results = (results || []).map((result) => this.normalizeResult(result));
                    this.totalFiles = total || this.results.length;
                    this.completedCount = this.results.length;
                    this.recalculateStats();
                },

                recalculateStats() {
                    this.completedCount = this.results.length;
                    this.passedCount = this.results.filter((result) => result.status === 'pass').length;
                    this.failedCount = this.results.filter((result) => result.status === 'fail').length;
                    this.errorCount = this.results.filter((result) => result.status === 'error').length;
                },

                normalizeResult(result) {
                    return {
                        ...result,
                        test_key: result?.test_key || this.extractTestKey(result?.filename),
                        checks: Array.isArray(result?.checks) ? result.checks : [],
                        warnings: Array.isArray(result?.warnings) ? result.warnings : [],
                        artifacts: Array.isArray(result?.artifacts) ? result.artifacts : [],
                    };
                },

                extractTestKey(filename) {
                    return String(filename || '').replace(/\.pdf$/i, '');
                },

                async runTestRequest(file) {
                    try {
                        const response = await fetch('{{ route('pdfTests.runSingleTest') }}', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({
                                test_key: file.path,
                                run_id: this.runId,
                            }),
                        });
                        const data = await response.json();
                        if (!response.ok || !data.success) {
                            throw new Error(data.message || 'PDF test failed');
                        }

                        return this.normalizeResult(data.result || {});
                    } catch (error) {
                        return this.normalizeResult({
                            test_key: file.path,
                            filename: file.filename,
                            description: file.description,
                            section_name: file.section_name,
                            status: 'error',
                            checks_passed: 0,
                            checks_total: 0,
                            checks: [],
                            error: error?.message || String(error),
                            warnings: [],
                            artifacts: [],
                        });
                    }
                },

                latestResultFor(testKey) {
                    return this.results.find((result) => result.test_key === testKey) || null;
                },

                statusBadgeClasses(status) {
                    if (status === 'pass') {
                        return 'bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400';
                    }

                    if (status === 'fail') {
                        return 'bg-danger-100 text-danger-700 dark:bg-danger-900/30 dark:text-danger-400';
                    }

                    return 'bg-warning-100 text-warning-700 dark:bg-warning-900/30 dark:text-warning-400';
                },

                nextRunId() {
                    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                        return window.crypto.randomUUID();
                    }

                    return `pdf-run-${Date.now()}-${Math.random().toString(16).slice(2)}`;
                },

                formatTimestamp(value) {
                    if (!value) {
                        return 'an unknown time';
                    }

                    const timestamp = new Date(value);
                    if (Number.isNaN(timestamp.getTime())) {
                        return value;
                    }

                    return new Intl.DateTimeFormat(undefined, {
                        dateStyle: 'medium',
                        timeStyle: 'short',
                    }).format(timestamp);
                },

                openArtifact(artifact) {
                    this.activeArtifact = artifact;
                },

                closeArtifact() {
                    this.activeArtifact = null;
                },
            };
        }
    </script>
</x-filament-panels::page>
