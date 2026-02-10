<x-filament-panels::page>
    <div x-data="overlayEditorTestRunner()" x-init="init()" class="space-y-6">
        {{-- Controls --}}
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <x-filament::button
                    x-on:click="startTests()"
                    x-bind:disabled="running"
                    icon="heroicon-o-play"
                    color="primary"
                >
                    <span x-show="!running">Start Overlay Tests</span>
                    <span x-show="running" x-cloak>Running...</span>
                </x-filament::button>

                <x-filament::button
                    x-show="running"
                    x-cloak
                    x-on:click="cancelTests()"
                    icon="heroicon-o-stop"
                    color="danger"
                >
                    Cancel
                </x-filament::button>
            </div>

            <div class="text-sm text-gray-500 dark:text-gray-400" x-show="totalFiles > 0">
                <span x-text="completedCount"></span> / <span x-text="totalFiles"></span> tests
            </div>
        </div>

        {{-- Progress Bar --}}
        <div x-show="running || completedCount > 0" x-cloak>
            <div class="relative w-full bg-gray-200 dark:bg-gray-700 rounded-full h-4 overflow-hidden">
                <div
                    class="h-full rounded-full transition-all duration-300 ease-out"
                    x-bind:class="{
                        'bg-primary-500': running,
                        'bg-success-500': !running && failedCount === 0 && errorCount === 0,
                        'bg-danger-500': !running && (failedCount > 0 || errorCount > 0)
                    }"
                    x-bind:style="'width: ' + progressPercent + '%'"
                ></div>
                <div class="absolute inset-0 flex items-center justify-center text-xs font-semibold"
                     x-bind:class="progressPercent > 50 ? 'text-white' : 'text-gray-700 dark:text-gray-300'">
                    <span x-text="progressPercent + '%'"></span>
                </div>
            </div>
        </div>

        {{-- Stats Cards --}}
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

        {{-- Currently Running --}}
        <div x-show="running && currentTest" x-cloak
             class="bg-primary-50 dark:bg-primary-950/20 border border-primary-200 dark:border-primary-800 rounded-xl p-4 flex items-center gap-3">
            <svg class="animate-spin h-5 w-5 text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <div>
                <div class="font-medium text-primary-700 dark:text-primary-300" x-text="'Validating: ' + currentTest"></div>
                <div class="text-sm text-primary-600 dark:text-primary-400" x-text="currentDescription"></div>
            </div>
        </div>

        {{-- Results Table --}}
        <div x-show="results.length > 0" x-cloak class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700/50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">#</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Result</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">PDF File</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Description</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Checks</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <template x-for="(r, idx) in results" :key="idx">
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors cursor-pointer"
                                x-on:click="toggleExpand(idx)"
                                x-bind:class="{
                                    'animate-fadeIn': true,
                                    'bg-success-50/30 dark:bg-success-950/10': r.status === 'pass',
                                    'bg-danger-50/30 dark:bg-danger-950/10': r.status === 'fail',
                                    'bg-warning-50/30 dark:bg-warning-950/10': r.status === 'error'
                                }">
                                <td class="px-4 py-2.5 text-gray-500" x-text="idx + 1"></td>
                                <td class="px-4 py-2.5">
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold uppercase"
                                          x-bind:class="{
                                              'bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400': r.status === 'pass',
                                              'bg-danger-100 text-danger-700 dark:bg-danger-900/30 dark:text-danger-400': r.status === 'fail',
                                              'bg-warning-100 text-warning-700 dark:bg-warning-900/30 dark:text-warning-400': r.status === 'error'
                                          }"
                                          x-text="r.status">
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-xs text-gray-700 dark:text-gray-300 max-w-xs truncate" x-text="r.filename"></td>
                                <td class="px-4 py-2.5 text-gray-600 dark:text-gray-400 max-w-sm truncate" x-text="r.description" x-bind:title="r.description"></td>
                                <td class="px-4 py-2.5"
                                    x-bind:class="r.checks_passed === r.checks_total ? 'text-success-600' : 'text-danger-600'">
                                    <span x-text="r.checks_passed + '/' + r.checks_total"></span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span x-show="r.error" class="text-xs text-danger-500 truncate max-w-[200px] inline-block" x-text="r.error" x-bind:title="r.error"></span>
                                    <span x-show="!r.error" class="text-xs text-success-500">All checks passed</span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Expanded Check Details (per-result) --}}
        <template x-for="(r, idx) in results" :key="'detail-' + idx">
            <div x-show="expandedIndex === idx" x-cloak x-transition
                 class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
                <div class="px-4 py-3 bg-gray-50 dark:bg-gray-700/50 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <h3 class="font-semibold text-gray-900 dark:text-white">
                        <span x-text="r.filename"></span> — Individual Checks
                    </h3>
                    <x-filament::button size="xs" color="gray" x-on:click="expandedIndex = null">
                        Close
                    </x-filament::button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50/50 dark:bg-gray-700/30">
                            <tr>
                                <th class="px-4 py-2.5 text-left font-medium text-gray-500 dark:text-gray-400 w-12">#</th>
                                <th class="px-4 py-2.5 text-left font-medium text-gray-500 dark:text-gray-400 w-20">Result</th>
                                <th class="px-4 py-2.5 text-left font-medium text-gray-500 dark:text-gray-400">Check</th>
                                <th class="px-4 py-2.5 text-left font-medium text-gray-500 dark:text-gray-400">Description</th>
                                <th class="px-4 py-2.5 text-left font-medium text-gray-500 dark:text-gray-400">Detail</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <template x-for="(check, ci) in (r.checks || [])" :key="ci">
                                <tr x-bind:class="{
                                    'bg-success-50/20 dark:bg-success-950/5': check.result === 'PASS',
                                    'bg-danger-50/20 dark:bg-danger-950/5': check.result === 'FAIL'
                                }">
                                    <td class="px-4 py-2 text-gray-400" x-text="ci + 1"></td>
                                    <td class="px-4 py-2">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold"
                                              x-bind:class="{
                                                  'bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400': check.result === 'PASS',
                                                  'bg-danger-100 text-danger-700 dark:bg-danger-900/30 dark:text-danger-400': check.result === 'FAIL'
                                              }"
                                              x-text="check.result">
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 font-medium text-gray-700 dark:text-gray-300" x-text="check.item"></td>
                                    <td class="px-4 py-2 text-gray-600 dark:text-gray-400 text-xs" x-text="check.description"></td>
                                    <td class="px-4 py-2 text-gray-500 dark:text-gray-500 text-xs max-w-md" x-text="check.detail" x-bind:title="check.detail"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        {{-- Empty State --}}
        <div x-show="!running && results.length === 0 && !loading" class="text-center py-12">
            <x-heroicon-o-beaker class="mx-auto h-12 w-12 text-gray-400" />
            <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No Tests Run</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Click "Start Overlay Tests" to validate PDF extraction for all test files in <code class="bg-gray-100 dark:bg-gray-700 px-1.5 py-0.5 rounded text-xs">tests/OverlayEditor/</code>.
                <br>Each PDF is extracted and compared against its ground truth. Checks include text match, position accuracy, font data, word-level integrity, and clean PDF generation.
            </p>
        </div>

        {{-- Loading State --}}
        <div x-show="loading" x-cloak class="text-center py-12">
            <svg class="animate-spin mx-auto h-8 w-8 text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Loading test files...</p>
        </div>

        {{-- Completion Banner --}}
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
                         x-text="'Validation Complete — ' + passedCount + ' passed, ' + failedCount + ' failed, ' + errorCount + ' errors out of ' + completedCount + ' PDFs'">
                    </div>
                    <div class="text-sm mt-1"
                         x-bind:class="failedCount === 0 && errorCount === 0 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400'"
                         x-text="failedCount === 0 && errorCount === 0
                             ? 'All extraction checks passed. PDF content matches extracted data.'
                             : 'Some checks failed. Click on a row above to see individual check details.'">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fadeIn {
            animation: fadeIn 0.3s ease-out;
        }
        [x-cloak] { display: none !important; }
    </style>

    <script>
        function overlayEditorTestRunner() {
            return {
                running: false,
                loading: false,
                finished: false,
                cancelled: false,
                totalFiles: 0,
                completedCount: 0,
                passedCount: 0,
                failedCount: 0,
                errorCount: 0,
                currentTest: '',
                currentDescription: '',
                results: [],
                runId: null,
                files: [],
                expandedIndex: null,

                get progressPercent() {
                    if (this.totalFiles === 0) return 0;
                    return Math.round((this.completedCount / this.totalFiles) * 100);
                },

                init() {
                    // Nothing to do on init
                },

                toggleExpand(idx) {
                    this.expandedIndex = this.expandedIndex === idx ? null : idx;
                },

                async startTests() {
                    this.running = true;
                    this.loading = true;
                    this.finished = false;
                    this.cancelled = false;
                    this.results = [];
                    this.completedCount = 0;
                    this.passedCount = 0;
                    this.failedCount = 0;
                    this.errorCount = 0;
                    this.currentTest = '';
                    this.currentDescription = '';
                    this.expandedIndex = null;

                    try {
                        // Fetch the list of test files
                        const listResponse = await fetch('/overlay-editor/test-files');
                        const listData = await listResponse.json();

                        if (!listData.success) {
                            alert('Failed to load test files: ' + (listData.message || 'Unknown error'));
                            this.running = false;
                            this.loading = false;
                            return;
                        }

                        this.files = listData.files;
                        this.totalFiles = listData.total;
                        this.runId = listData.run_id;
                        this.loading = false;

                        // Run tests one by one
                        for (let i = 0; i < this.files.length; i++) {
                            if (this.cancelled) break;

                            const file = this.files[i];
                            this.currentTest = file.filename;
                            this.currentDescription = file.description;

                            try {
                                const response = await fetch('/overlay-editor/run-single-test', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                    },
                                    body: JSON.stringify({
                                        file_path: file.path,
                                        run_id: this.runId,
                                    }),
                                });

                                const data = await response.json();

                                if (data.success && data.result) {
                                    this.results.push(data.result);
                                    this.completedCount++;

                                    if (data.result.status === 'pass') {
                                        this.passedCount++;
                                    } else if (data.result.status === 'fail') {
                                        this.failedCount++;
                                    } else {
                                        this.errorCount++;
                                    }
                                } else {
                                    // API error
                                    this.results.push({
                                        filename: file.filename,
                                        description: file.description,
                                        test_category: 'Overlay Editor',
                                        section_name: 'Extraction Validation',
                                        status: 'error',
                                        checks_passed: 0,
                                        checks_total: 0,
                                        checks: [],
                                        error: data.message || 'Request failed',
                                    });
                                    this.completedCount++;
                                    this.errorCount++;
                                }
                            } catch (fetchError) {
                                this.results.push({
                                    filename: file.filename,
                                    description: file.description,
                                    test_category: 'Overlay Editor',
                                    section_name: 'Extraction Validation',
                                    status: 'error',
                                    checks_passed: 0,
                                    checks_total: 0,
                                    checks: [],
                                    error: 'Network error: ' + fetchError.message,
                                });
                                this.completedCount++;
                                this.errorCount++;
                            }

                            // Scroll the latest result into view
                            this.$nextTick(() => {
                                const table = document.querySelector('table tbody tr:last-child');
                                if (table) {
                                    table.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                                }
                            });
                        }
                    } catch (err) {
                        alert('Error starting tests: ' + err.message);
                    }

                    this.running = false;
                    this.currentTest = '';
                    this.currentDescription = '';
                    this.finished = true;
                },

                cancelTests() {
                    this.cancelled = true;
                    this.running = false;
                    this.finished = true;
                },
            };
        }
    </script>
</x-filament-panels::page>
