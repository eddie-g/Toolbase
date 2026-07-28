<x-filament-panels::page>
    <div x-data="pdfTestRunner()" x-init="init()">

        {{-- ═══════════════════════════════════════════════════════════
             LIST SCREEN
        ═══════════════════════════════════════════════════════════ --}}
        <div x-show="screen === 'list'" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="space-y-6">

            {{-- Top bar --}}
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white">PDF Flow Tests</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                        Run individual tests or the full suite. Results are saved automatically.
                    </p>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <a href="{{ \App\Filament\Resources\OverlayEditorTestResource::getUrl() }}">
                        <x-filament::button color="gray" size="sm" icon="heroicon-o-arrow-left">Results</x-filament::button>
                    </a>
                    <a href="{{ route('filament.admin.pages.debug-pdf') }}">
                        <x-filament::button color="gray" size="sm" icon="heroicon-o-bug-ant">Debug PDF</x-filament::button>
                    </a>
                    <x-filament::button
                        x-show="activeTab !== 'upload-tests'"
                        x-on:click="startAllTests()"
                        x-bind:disabled="loading || globalRunning"
                        icon="heroicon-o-play"
                        color="danger"
                        size="sm"
                    >
                        <span x-show="!globalRunning">Run All</span>
                        <span x-show="globalRunning" x-cloak>Running…</span>
                    </x-filament::button>
                </div>
            </div>

            {{-- Global progress bar (run-all mode) --}}
            <template x-if="activeTab !== 'upload-tests' && (globalRunning || (globalFinished && allRunResults.length > 0))">
                <div class="space-y-2">
                    <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                        <span x-text="globalRunning ? 'Running ' + allRunResults.length + ' of ' + visibleFiles.length + ' tests…' : 'Run complete'"></span>
                        <span x-text="allRunPassedCount + ' passed · ' + allRunFailedCount + ' failed · ' + allRunErrorCount + ' errors'"></span>
                    </div>
                    <div class="relative w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2 overflow-hidden">
                        <div class="h-full rounded-full transition-all duration-500 ease-out"
                             x-bind:class="globalRunning ? 'bg-danger-500' : (allRunFailedCount === 0 && allRunErrorCount === 0 ? 'bg-success-500' : 'bg-warning-500')"
                             x-bind:style="'width: ' + (visibleFiles.length ? Math.round((allRunResults.length / visibleFiles.length) * 100) : 0) + '%'">
                        </div>
                    </div>
                </div>
            </template>

            {{-- Editor tabs (split tests by which editor URL they exercise) --}}
            <div x-show="!loading" class="border-b border-gray-200 dark:border-gray-700">
                <nav class="-mb-px flex gap-6" aria-label="Editor tabs">
                    <button type="button"
                            x-on:click="setActiveTab('upload-tests')"
                            x-bind:class="activeTab === 'upload-tests'
                                ? 'border-danger-500 text-danger-600 dark:text-danger-400'
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200'"
                            class="whitespace-nowrap py-3 px-1 border-b-2 text-sm font-medium transition-colors">
                        PDF upload tests
                        <span class="ml-1.5 inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300"
                              x-text="uploadTests.length"></span>
                    </button>
                    <button type="button"
                            x-on:click="setActiveTab('edit-new')"
                            x-bind:class="activeTab === 'edit-new'
                                ? 'border-danger-500 text-danger-600 dark:text-danger-400'
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200'"
                            class="whitespace-nowrap py-3 px-1 border-b-2 text-sm font-medium transition-colors">
                        New editor <span class="text-xs text-gray-400">/edit-new</span>
                        <span class="ml-1.5 inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300"
                              x-text="editorCount('edit-new')"></span>
                    </button>
                </nav>
            </div>

            <style>
                .pdf-upload-workspace { display: grid; min-width: 0; max-width: 100%; gap: 20px; }
                .pdf-upload-card { padding: 22px; border: 1px solid #e2e8f0; border-radius: 14px; background: #fff; box-shadow: 0 1px 2px rgba(15, 23, 42, .04); }
                .pdf-upload-card h3, .pdf-upload-list-head h3 { margin: 0; color: #0f172a; font-size: 17px; font-weight: 750; }
                .pdf-upload-card p, .pdf-upload-list-head p { margin: 5px 0 0; color: #64748b; font-size: 13px; line-height: 1.5; }
                .pdf-upload-form { display: grid; grid-template-columns: minmax(240px, 1fr) auto; gap: 12px; align-items: end; margin-top: 18px; }
                .pdf-upload-input-stack { display: grid; gap: 11px; }
                .pdf-upload-file-label { display: grid; gap: 6px; color: #475569; font-size: 12px; font-weight: 700; }
                .pdf-upload-file { box-sizing: border-box; width: 100%; min-height: 42px; padding: 7px; border: 1px solid #cbd5e1; border-radius: 9px; color: #334155; background: #fff; }
                .pdf-upload-primary, .pdf-upload-secondary, .pdf-upload-danger, .pdf-upload-refresh, .pdf-upload-test { display: inline-flex; min-height: 40px; align-items: center; justify-content: center; box-sizing: border-box; border-radius: 9px; padding: 0 15px; font-size: 13px; font-weight: 750; text-decoration: none !important; cursor: pointer; }
                .pdf-upload-primary { border: 1px solid #2563eb; color: #fff !important; background: #2563eb !important; }
                .pdf-upload-primary:hover { border-color: #1d4ed8; background: #1d4ed8 !important; }
                .pdf-upload-test { border: 1px solid #7c3aed; color: #fff !important; background: #7c3aed !important; }
                .pdf-upload-test:hover { border-color: #6d28d9; background: #6d28d9 !important; }
                .pdf-upload-danger { border: 1px solid #fecaca; color: #b91c1c !important; background: #fff !important; }
                .pdf-upload-danger:hover { border-color: #ef4444; background: #fef2f2 !important; }
                .pdf-upload-primary:disabled, .pdf-upload-danger:disabled, .pdf-upload-test:disabled { cursor: wait; opacity: .65; }
                .pdf-upload-secondary, .pdf-upload-refresh { border: 1px solid #cbd5e1; color: #334155 !important; background: #fff !important; }
                .pdf-upload-secondary:hover, .pdf-upload-refresh:hover { border-color: #94a3b8; background: #f8fafc !important; }
                .pdf-upload-message { margin-top: 12px; padding: 9px 11px; border-radius: 8px; font-size: 13px; }
                .pdf-upload-message.is-error { color: #991b1b; background: #fef2f2; }
                .pdf-upload-message.is-success { color: #166534; background: #f0fdf4; }
                .pdf-upload-list-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
                .pdf-upload-list-head-actions { display: flex; flex: 0 0 auto; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
                .pdf-upload-loading, .pdf-upload-empty { padding: 32px; border: 1px dashed #cbd5e1; border-radius: 12px; color: #64748b; background: rgba(255,255,255,.6); text-align: center; font-size: 13px; }
                .pdf-upload-list { display: grid; grid-template-columns: minmax(0, 1fr); min-width: 0; max-width: 100%; gap: 10px; }
                .pdf-upload-row { display: flex; width: 100%; max-width: 100%; align-items: flex-start; justify-content: space-between; gap: 18px; box-sizing: border-box; overflow: hidden; padding: 16px 18px; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; }
                .pdf-upload-file-info { flex: 1 1 0%; width: 0; min-width: 0; max-width: 100%; }
                .pdf-upload-file-name { overflow: hidden; color: #0f172a; font-size: 15px; font-weight: 750; text-overflow: ellipsis; white-space: nowrap; }
                .pdf-upload-file-meta { display: flex; flex-wrap: wrap; gap: 7px 14px; margin-top: 4px; color: #64748b; font-size: 12px; }
                .pdf-upload-cases { display: grid; grid-template-columns: minmax(0, 1fr); min-width: 0; max-width: 100%; gap: 8px; margin-top: 12px; }
                .pdf-upload-case { min-width: 0; max-width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #dbeafe; border-radius: 9px; background: #f8fbff; }
                .pdf-upload-case-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
                .pdf-upload-case-head > div { min-width: 0; }
                .pdf-upload-case-test-id { display: block; margin-bottom: 4px; color: #64748b; font-size: 10px; line-height: 1.35; overflow-wrap: anywhere; word-break: break-word; }
                .pdf-upload-case-test-id strong { color: #475569; font-weight: 800; }
                .pdf-upload-case-id { display: inline; color: #1d4ed8; font-size: 11px; overflow-wrap: anywhere; word-break: break-word; }
                .pdf-upload-case-page { margin-left: 7px; color: #64748b; font-size: 11px; }
                .pdf-upload-case-target { margin-top: 5px; color: #64748b; font-size: 11px; line-height: 1.45; overflow-wrap: anywhere; word-break: break-word; white-space: normal; }
                .pdf-upload-case-comment { margin-top: 5px; color: #334155; font-size: 12px; line-height: 1.45; overflow-wrap: anywhere; word-break: break-word; white-space: pre-wrap; }
                .pdf-upload-no-cases { margin-top: 10px; color: #64748b; font-size: 12px; }
                .pdf-upload-grouping { display: inline-flex; width: fit-content; max-width: 100%; align-items: center; gap: 9px; margin-top: 11px; color: #475569; font-size: 12px; font-weight: 750; cursor: pointer; }
                .pdf-upload-grouping input { position: absolute; width: 1px; height: 1px; overflow: hidden; opacity: 0; pointer-events: none; }
                .pdf-upload-grouping-track { position: relative; width: 34px; height: 19px; flex: 0 0 34px; border-radius: 999px; background: #cbd5e1; transition: background .15s ease; }
                .pdf-upload-grouping-track::after { position: absolute; top: 3px; left: 3px; width: 13px; height: 13px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(15, 23, 42, .3); content: ''; transition: transform .15s ease; }
                .pdf-upload-grouping input:checked + .pdf-upload-grouping-track { background: #2563eb; }
                .pdf-upload-grouping input:checked + .pdf-upload-grouping-track::after { transform: translateX(15px); }
                .pdf-upload-grouping input:focus-visible + .pdf-upload-grouping-track { outline: 3px solid rgba(37, 99, 235, .22); outline-offset: 2px; }
                .pdf-upload-grouping input:disabled + .pdf-upload-grouping-track { opacity: .55; }
                .pdf-upload-grouping-help { color: #94a3b8; font-weight: 500; }
                .pdf-upload-batch-status { padding: 12px 14px; border: 1px solid #ddd6fe; border-radius: 10px; color: #5b21b6; background: #f5f3ff; font-size: 12px; }
                .pdf-upload-batch-status strong { font-weight: 800; }
                .pdf-upload-test-result { display: flex; flex-wrap: wrap; gap: 5px 10px; align-items: center; margin-top: 10px; padding: 8px 10px; border-radius: 8px; color: #334155; background: #f8fafc; font-size: 12px; }
                .pdf-upload-test-result.is-pass { color: #166534; background: #f0fdf4; }
                .pdf-upload-test-result.is-fail { color: #991b1b; background: #fef2f2; }
                .pdf-upload-test-result.is-error { color: #92400e; background: #fffbeb; }
                .pdf-upload-test-result strong { font-weight: 800; }
                .pdf-upload-test-result a { color: inherit; font-weight: 750; text-decoration: underline; }
                .pdf-upload-actions { display: flex; flex: 0 0 auto; max-width: 100%; flex-wrap: wrap; gap: 8px; }
                html.dark .pdf-upload-card, html.dark .pdf-upload-row { border-color: #374151; background: #1f2937; }
                html.dark .pdf-upload-case { border-color: #374151; background: #111827; }
                html.dark .pdf-upload-card h3, html.dark .pdf-upload-list-head h3, html.dark .pdf-upload-file-name { color: #f8fafc; }
                html.dark .pdf-upload-file { border-color: #4b5563; color: #e5e7eb; background: #111827; }
                html.dark .pdf-upload-secondary, html.dark .pdf-upload-refresh { border-color: #4b5563; color: #e5e7eb !important; background: #1f2937 !important; }
                html.dark .pdf-upload-danger { border-color: #7f1d1d; color: #fca5a5 !important; background: #1f2937 !important; }
                html.dark .pdf-upload-grouping { color: #d1d5db; }
                html.dark .pdf-upload-batch-status { border-color: #5b21b6; color: #ddd6fe; background: rgba(76, 29, 149, .28); }
                @media (max-width: 700px) {
                    .pdf-upload-form { grid-template-columns: 1fr; }
                    .pdf-upload-list-head { align-items: stretch; flex-direction: column; }
                    .pdf-upload-list-head-actions { justify-content: stretch; }
                    .pdf-upload-list-head-actions > * { flex: 1 1 auto; }
                    .pdf-upload-row { align-items: stretch; flex-direction: column; }
                    .pdf-upload-file-info { width: 100%; }
                    .pdf-upload-actions { width: 100%; }
                    .pdf-upload-actions > * { flex: 1 1 auto; }
                }
            </style>

            <div x-show="activeTab === 'upload-tests'" x-cloak class="pdf-upload-workspace">
                <section class="pdf-upload-card">
                    <h3>Upload a PDF</h3>
                    <p>The PDF opens in a read-only review screen with the same blue boxes as <code>?pdfjs=1</code>. Click a box, add the test instruction, and save.</p>

                    <form x-on:submit.prevent="submitPdfUpload()" class="pdf-upload-form">
                        <div class="pdf-upload-input-stack">
                            <label class="pdf-upload-file-label">
                                <span>PDF file</span>
                                <input x-ref="uploadTestPdf"
                                       class="pdf-upload-file"
                                       type="file"
                                       name="pdf"
                                       accept="application/pdf,.pdf"
                                       x-bind:disabled="uploading || uploadTestsBusy"
                                       x-on:change="uploadError = ''; uploadSuccess = ''">
                            </label>
                            <label class="pdf-upload-grouping">
                                <input type="checkbox"
                                       x-model="uploadParagraphGrouping"
                                       x-bind:disabled="uploading || uploadTestsBusy">
                                <span class="pdf-upload-grouping-track" aria-hidden="true"></span>
                                <span>
                                    Apply paragraph grouping
                                    <span class="pdf-upload-grouping-help">Match the original document editor</span>
                                </span>
                            </label>
                        </div>
                        <button class="pdf-upload-primary" type="submit" x-bind:disabled="uploading || uploadTestsBusy">
                            <span x-text="uploading ? 'Uploading…' : 'Upload and review'"></span>
                        </button>
                    </form>

                    <div x-show="uploadError" x-cloak class="pdf-upload-message is-error" x-text="uploadError"></div>
                    <div x-show="uploadSuccess" x-cloak class="pdf-upload-message is-success" x-text="uploadSuccess"></div>
                </section>

                <div class="pdf-upload-list-head">
                    <div>
                        <h3>Uploaded PDFs</h3>
                        <p>Open any PDF to select an annotation and write or update its test comment.</p>
                    </div>
                    <div class="pdf-upload-list-head-actions">
                        <button class="pdf-upload-test"
                                type="button"
                                x-on:click="runAllUploadedPdfTests()"
                                x-bind:disabled="uploadTestsBusy || allUploadTestCasesCount === 0">
                            <span x-text="uploadBatchRunning && uploadBatchScope === 'all'
                                ? 'Running ' + uploadBatchCompleted + '/' + uploadBatchTotal + '…'
                                : 'Run ALL uploaded PDF tests'"></span>
                        </button>
                        <button class="pdf-upload-refresh"
                                type="button"
                                x-on:click="loadUploadTests()"
                                x-bind:disabled="uploadTestsLoading || uploadTestsBusy">
                            <span x-text="uploadTestsLoading ? 'Refreshing…' : 'Refresh'"></span>
                        </button>
                    </div>
                </div>

                <div x-show="uploadBatchRunning || uploadBatchFinished"
                     x-cloak
                     class="pdf-upload-batch-status">
                    <strong x-text="uploadBatchRunning ? 'Test run in progress' : 'Test run complete'"></strong>
                    <span x-text="' · ' + uploadBatchCompleted + '/' + uploadBatchTotal + ' finished · ' + uploadBatchPassed + ' passed · ' + uploadBatchFailed + ' failed · ' + uploadBatchErrors + ' errors'"></span>
                </div>

                <div x-show="uploadTestsLoading" class="pdf-upload-loading">Loading uploaded PDFs…</div>
                <div x-show="!uploadTestsLoading && uploadTests.length === 0" class="pdf-upload-empty">No uploaded PDFs yet.</div>

                <div x-show="!uploadTestsLoading && uploadTests.length > 0" class="pdf-upload-list">
                    <template x-for="test in uploadTests" :key="test.id">
                        <article class="pdf-upload-row">
                            <div class="pdf-upload-file-info">
                                <div class="pdf-upload-file-name" x-text="test.original_name"></div>
                                <div class="pdf-upload-file-meta">
                                    <span x-text="formatBytes(test.size_bytes)"></span>
                                    <span x-text="'Uploaded ' + formatTimestamp(test.created_at)"></span>
                                    <span x-text="(test.case_count || 0) + ((test.case_count || 0) === 1 ? ' saved annotation test' : ' saved annotation tests')"></span>
                                </div>
                                <label class="pdf-upload-grouping">
                                    <input type="checkbox"
                                           x-bind:checked="Boolean(test.paragraph_grouping_enabled)"
                                           x-on:change="updateUploadParagraphGrouping(test, $event.target.checked)"
                                           x-bind:disabled="uploadTestsBusy || updatingParagraphGroupingId !== null">
                                    <span class="pdf-upload-grouping-track" aria-hidden="true"></span>
                                    <span x-text="updatingParagraphGroupingId === test.id
                                        ? 'Updating paragraph grouping…'
                                        : 'Apply paragraph grouping'"></span>
                                </label>
                                <div x-show="!Array.isArray(test.cases) || test.cases.length === 0"
                                     x-cloak
                                     class="pdf-upload-no-cases">
                                    No annotation tests saved yet. Select an annotation to add one.
                                </div>
                                <div x-show="Array.isArray(test.cases) && test.cases.length > 0"
                                     x-cloak
                                     class="pdf-upload-cases">
                                    <template x-for="testCase in (test.cases || [])" :key="testCase.id">
                                        <div class="pdf-upload-case">
                                            <div class="pdf-upload-case-head">
                                                <div>
                                                    <span class="pdf-upload-case-test-id">
                                                        <strong>Test ID</strong>
                                                        <span x-text="testCase.test_id"></span>
                                                    </span>
                                                    <code class="pdf-upload-case-id" x-text="testCase.annotation_id"></code>
                                                    <span class="pdf-upload-case-page"
                                                          x-text="'Page ' + (Number(testCase.page_index) + 1)"></span>
                                                </div>
                                                <button class="pdf-upload-test"
                                                        type="button"
                                                        x-on:click="runUploadTest(test, testCase)"
                                                        x-bind:disabled="uploadTestsBusy"
                                                        x-text="runningUploadTestCaseId === testCase.id ? 'Testing…' : 'Test'"></button>
                                            </div>
                                            <div class="pdf-upload-case-target"
                                                 x-show="testCase.target_text"
                                                 x-text="testCase.target_text"></div>
                                            <div class="pdf-upload-case-comment" x-text="testCase.test_comment"></div>
                                            <div x-show="uploadTestResults[testCase.id]"
                                                 x-cloak
                                                 class="pdf-upload-test-result"
                                                 x-bind:class="'is-' + (uploadTestResults[testCase.id]?.status || 'error')">
                                                <strong x-text="String(uploadTestResults[testCase.id]?.status || 'error').toUpperCase()"></strong>
                                                <span x-text="(uploadTestResults[testCase.id]?.checks_passed || 0) + '/' + (uploadTestResults[testCase.id]?.checks_total || 0) + ' checks passed'"></span>
                                                <span x-show="uploadTestResults[testCase.id]?.error"
                                                      x-text="uploadTestResults[testCase.id]?.error"></span>
                                                <a x-show="uploadTestResults[testCase.id]?.id"
                                                   x-bind:href="uploadResultDetailsUrl(uploadTestResults[testCase.id])">View details</a>
                                                <template x-for="artifact in (uploadTestResults[testCase.id]?.artifacts || []).filter((item) => item.kind === 'pdf')" :key="artifact.filename">
                                                    <a x-bind:href="artifact.url" target="_blank" rel="noopener" x-text="artifact.label || 'Resulting PDF'"></a>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                            <div class="pdf-upload-actions">
                                <button class="pdf-upload-test"
                                        type="button"
                                        x-on:click="runAllUploadTestsForPdf(test)"
                                        x-bind:disabled="uploadTestsBusy || !Array.isArray(test.cases) || test.cases.length === 0"
                                        x-text="uploadBatchRunning && uploadBatchScope === 'pdf:' + test.id
                                            ? 'Running ' + uploadBatchCompleted + '/' + uploadBatchTotal + '…'
                                            : 'Run all tests for PDF'"></button>
                                <a class="pdf-upload-secondary" x-bind:href="test.original_pdf_url" target="_blank" rel="noopener">Open PDF</a>
                                <a class="pdf-upload-primary" x-bind:href="test.review_url">Select annotation</a>
                                <button class="pdf-upload-danger"
                                        type="button"
                                        x-on:click="deleteUploadTest(test)"
                                        x-bind:disabled="deletingUploadTestId === test.id || uploadTestsBusy"
                                        x-text="deletingUploadTestId === test.id ? 'Deleting…' : 'Delete'"></button>
                            </div>
                        </article>
                    </template>
                </div>
            </div>

            {{-- Category filters --}}
            <div x-show="activeTab !== 'upload-tests' && !loading && visibleCategories.length" class="flex flex-wrap items-center gap-2">
                <button type="button"
                        x-on:click="setActiveCategory('all')"
                        x-bind:class="activeCategory === 'all'
                            ? 'bg-danger-600 text-white ring-danger-600'
                            : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 ring-gray-200 dark:ring-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/70'"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold ring-1 transition-colors">
                    All categories
                    <span class="opacity-75" x-text="editorCount(activeTab)"></span>
                </button>
                <template x-for="category in visibleCategories" :key="category">
                    <button type="button"
                            x-on:click="setActiveCategory(category)"
                            x-bind:class="activeCategory === category
                                ? 'bg-danger-600 text-white ring-danger-600'
                                : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 ring-gray-200 dark:ring-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/70'"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold ring-1 transition-colors">
                        <span x-text="category"></span>
                        <span class="opacity-75" x-text="categoryCount(category)"></span>
                    </button>
                </template>
            </div>

            {{-- Loading state --}}
            <template x-if="activeTab !== 'upload-tests' && loading">
                <div class="flex items-center gap-3 py-12 justify-center">
                    <svg class="animate-spin h-5 w-5 text-danger-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span class="text-sm text-gray-500 dark:text-gray-400">Loading tests…</span>
                </div>
            </template>

            {{-- Test cards grid --}}
            <div x-show="activeTab !== 'upload-tests' && !loading && visibleFiles.length > 0" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <template x-for="file in visibleFiles" :key="file.path">
                    <div class="group relative bg-white dark:bg-gray-800 rounded-2xl ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden flex flex-col cursor-pointer transition-shadow hover:shadow-md"
                         x-on:click="openTest(file)">

                        {{-- Status stripe along the top --}}
                        <div class="h-1 w-full transition-colors duration-300"
                             x-bind:class="latestResultFor(file.path)
                                ? (latestResultFor(file.path).status === 'pass' ? 'bg-success-400' : (latestResultFor(file.path).status === 'fail' ? 'bg-danger-500' : 'bg-warning-400'))
                                : 'bg-gray-200 dark:bg-gray-700'">
                        </div>

                        <div class="p-5 flex flex-col gap-4 flex-1">
                            {{-- Header row --}}
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="text-xs font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500"
                                              x-text="'Test ' + (visibleFiles.indexOf(file) + 1)"></span>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300"
                                              x-text="categoryName(file)"></span>
                                        <template x-if="latestResultFor(file.path)">
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold uppercase"
                                                  x-bind:class="latestResultFor(file.path).status === 'pass'
                                                      ? 'bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400'
                                                      : (latestResultFor(file.path).status === 'fail'
                                                          ? 'bg-danger-100 text-danger-700 dark:bg-danger-900/30 dark:text-danger-400'
                                                          : 'bg-warning-100 text-warning-700 dark:bg-warning-900/30 dark:text-warning-400')"
                                                  x-text="latestResultFor(file.path).status">
                                            </span>
                                        </template>
                                    </div>
                                    <div class="mt-1 font-semibold text-gray-900 dark:text-white text-base leading-snug"
                                         x-text="file.section_name || file.filename"></div>
                                    <div class="mt-1 text-sm text-gray-500 dark:text-gray-400 line-clamp-2"
                                         x-text="file.description || ''"></div>
                                </div>

                                {{-- Run button --}}
                                <button type="button"
                                        x-on:click.stop="openAndRunTest(file)"
                                        x-bind:disabled="globalRunning"
                                        class="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium bg-danger-50 text-danger-700 hover:bg-danger-100 dark:bg-danger-950/40 dark:text-danger-400 dark:hover:bg-danger-950/60 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                                    <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                    Run
                                </button>
                            </div>

                            {{-- Criteria list --}}
                            <template x-if="file.criteria && file.criteria.length">
                                <ul class="space-y-1.5">
                                    <template x-for="(criterion, ci) in file.criteria" :key="ci">
                                        <li class="flex items-start gap-2">
                                            <template x-if="latestResultFor(file.path) && latestResultFor(file.path).checks && latestResultFor(file.path).checks[ci]">
                                                {{-- Actual check result icon --}}
                                                <template x-if="latestResultFor(file.path).checks[ci].result === 'PASS'">
                                                    <svg class="h-4 w-4 mt-0.5 text-success-500 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                </template>
                                                <template x-if="latestResultFor(file.path).checks[ci].result !== 'PASS'">
                                                    <svg class="h-4 w-4 mt-0.5 text-danger-500 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                </template>
                                            </template>
                                            <template x-if="!latestResultFor(file.path) || !latestResultFor(file.path).checks || !latestResultFor(file.path).checks[ci]">
                                                <svg class="h-4 w-4 mt-0.5 text-gray-300 dark:text-gray-600 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                                            </template>
                                            <span class="text-xs text-gray-600 dark:text-gray-400 leading-5" x-text="criterion"></span>
                                        </li>
                                    </template>
                                </ul>
                            </template>

                            {{-- Footer --}}
                            <div class="mt-auto pt-3 border-t border-gray-100 dark:border-gray-700/50 flex items-center justify-between text-xs text-gray-400 dark:text-gray-500">
                                <template x-if="latestResultFor(file.path)">
                                    <span x-text="'Last run: ' + formatTimestamp(latestResultFor(file.path).created_at || latestRunCreatedAt)"></span>
                                </template>
                                <template x-if="!latestResultFor(file.path)"><span>No runs yet</span></template>
                                <template x-if="latestResultFor(file.path)">
                                    <span x-text="latestResultFor(file.path).checks_passed + '/' + latestResultFor(file.path).checks_total + ' checks'"></span>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Empty state when the active editor tab has no tests --}}
            <div x-show="activeTab !== 'upload-tests' && !loading && visibleFiles.length === 0" class="text-center py-12 text-sm text-gray-500 dark:text-gray-400">
                No tests registered for this editor yet.
            </div>
        </div>

        {{-- ═══════════════════════════════════════════════════════════
             DETAIL / RUNNING SCREEN
        ═══════════════════════════════════════════════════════════ --}}
        <div x-show="screen === 'detail'" x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-1"
             x-transition:enter-end="opacity-100 translate-y-0"
             class="space-y-6">

            {{-- Back nav + title --}}
            <div class="flex items-center gap-4">
                <button type="button"
                        x-on:click="closeTest()"
                        class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white transition-colors">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                    All tests
                </button>
                <div class="h-4 w-px bg-gray-200 dark:bg-gray-700"></div>
                <div class="flex items-center gap-2 min-w-0">
                    <span class="text-xs font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 shrink-0"
                          x-text="activeFile ? 'Test ' + (visibleFiles.indexOf(activeFile) + 1) : ''"></span>
                    <template x-if="activeFile">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300"
                              x-text="categoryName(activeFile)"></span>
                    </template>
                    <span class="font-semibold text-gray-900 dark:text-white truncate"
                          x-text="activeFile ? (activeFile.section_name || activeFile.filename) : ''"></span>
                </div>

                {{-- Status badge --}}
                <div class="ml-auto shrink-0 flex items-center gap-3">
                    <template x-if="detailRunning">
                        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-danger-50 dark:bg-danger-950/40 text-danger-600 dark:text-danger-400 text-sm font-medium">
                            <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                            Running…
                        </div>
                    </template>
                    <template x-if="!detailRunning && detailResult">
                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-sm font-semibold uppercase"
                              x-bind:class="detailResult.status === 'pass'
                                  ? 'bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400'
                                  : (detailResult.status === 'fail'
                                      ? 'bg-danger-100 text-danger-700 dark:bg-danger-900/30 dark:text-danger-400'
                                      : 'bg-warning-100 text-warning-700 dark:bg-warning-900/30 dark:text-warning-400')"
                              x-text="detailResult.status">
                        </span>
                    </template>
                    <template x-if="!detailRunning && detailResult">
                        <button type="button"
                                x-on:click="runDetailTest()"
                                x-bind:disabled="memberRunningKey !== null"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium bg-danger-50 text-danger-700 hover:bg-danger-100 dark:bg-danger-950/40 dark:text-danger-400 dark:hover:bg-danger-950/60 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                            <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                            <span x-text="isSuite ? 'Re-run All' : 'Re-run'"></span>
                        </button>
                    </template>
                    <template x-if="!detailRunning && !detailResult">
                        <button type="button"
                                x-on:click="runDetailTest()"
                                x-bind:disabled="memberRunningKey !== null"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium bg-danger-600 text-white hover:bg-danger-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                            <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                            <span x-text="isSuite ? 'Run All in Suite' : 'Run Test'"></span>
                        </button>
                    </template>
                </div>
            </div>

            {{-- Description --}}
            <template x-if="activeFile">
                <p class="text-sm text-gray-500 dark:text-gray-400" x-text="activeFile.description || ''"></p>
            </template>

            {{-- Suite member tests: per-test Run buttons (only for suite entries) --}}
            <template x-if="isSuite">
                <div class="bg-white dark:bg-gray-800 rounded-2xl ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between gap-3">
                        <div>
                            <div class="font-semibold text-gray-900 dark:text-white text-sm">Suite Tests</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                <span x-text="activeFile.member_tests.length"></span> tests · run individually or use “Run All in Suite” above
                            </div>
                        </div>
                    </div>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700/50">
                        <template x-for="(member, mi) in activeFile.member_tests" :key="'member-' + member.path">
                            <li class="px-5 py-3 flex items-start gap-3">
                                <div class="mt-1 text-xs font-semibold text-gray-400 dark:text-gray-500 w-6 text-right shrink-0"
                                     x-text="(mi + 1) + '.'"></div>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-medium text-gray-900 dark:text-white" x-text="member.section_name || member.path"></div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5" x-text="member.description || ''"></div>
                                </div>
                                <div class="shrink-0 flex items-center gap-2">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide"
                                          x-bind:class="memberStatusClass(member)"
                                          x-text="memberStatusLabel(member)"></span>
                                    <template x-if="memberRunningKey === member.path">
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium bg-danger-50 text-danger-700 dark:bg-danger-950/40 dark:text-danger-400">
                                            <svg class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                            Running…
                                        </span>
                                    </template>
                                    <template x-if="memberRunningKey !== member.path">
                                        <button type="button"
                                                x-on:click="runMemberTest(member)"
                                                x-bind:disabled="memberRunningKey !== null || detailRunning"
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium bg-danger-600 text-white hover:bg-danger-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                                            <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                            <span x-text="memberStatus(member) ? 'Re-run' : 'Run'"></span>
                                        </button>
                                    </template>
                                </div>
                            </li>
                        </template>
                    </ul>
                </div>
            </template>

            {{-- Two-column layout: checklist + artifacts --}}
            <div class="grid grid-cols-1 xl:grid-cols-5 gap-6 items-start">

                {{-- Checklist (left / wider column) --}}
                <div class="xl:col-span-2 bg-white dark:bg-gray-800 rounded-2xl ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                        <div class="font-semibold text-gray-900 dark:text-white text-sm">Checks</div>
                        <template x-if="detailResult && !detailRunning">
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"
                                 x-text="detailResult.checks_passed + ' of ' + detailResult.checks_total + ' passed'"></div>
                        </template>
                        <template x-if="detailRunning">
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Running…</div>
                        </template>
                    </div>

                    <ul class="divide-y divide-gray-100 dark:divide-gray-700/50">
                        {{-- While running — show criteria as pending items, animate current one --}}
                        <template x-if="detailRunning && activeFile && activeFile.criteria && activeFile.criteria.length">
                            <template x-for="(criterion, ci) in activeFile.criteria" :key="'pending-' + ci">
                                <li class="px-5 py-3.5 flex items-start gap-3 transition-colors"
                                    x-bind:class="ci === fakeProgressIndex ? 'bg-danger-50/50 dark:bg-danger-950/10' : ''">
                                    <div class="w-10 flex-shrink-0 flex items-start gap-2">
                                        <div class="mt-0.5 h-5 min-w-[1.25rem] text-right text-xs font-semibold text-gray-400 dark:text-gray-500"
                                             x-text="ci + 1"></div>
                                        <div class="mt-0.5 h-5 w-5 flex items-center justify-center">
                                            <template x-if="ci < fakeProgressIndex">
                                                <svg class="h-4 w-4 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                            </template>
                                            <template x-if="ci === fakeProgressIndex">
                                                <svg class="animate-spin h-4 w-4 text-danger-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                                            </template>
                                            <template x-if="ci > fakeProgressIndex">
                                                <div class="h-4 w-4 rounded-full border-2 border-gray-200 dark:border-gray-700"></div>
                                            </template>
                                        </div>
                                    </div>
                                    <span class="text-sm leading-snug"
                                          x-bind:class="ci === fakeProgressIndex ? 'text-gray-900 dark:text-white font-medium' : 'text-gray-400 dark:text-gray-500'"
                                          x-text="criterion"></span>
                                </li>
                            </template>
                        </template>

                        {{-- After run — show actual checks, revealed one by one --}}
                        <template x-if="!detailRunning && detailResult && detailResult.checks && detailResult.checks.length">
                            <template x-for="(check, ci) in detailResult.checks" :key="'check-' + ci">
                                <li class="px-5 py-3.5 flex items-start gap-3 pdf-check-item"
                                    x-bind:class="ci < revealIndex ? 'pdf-check-revealed' : 'pdf-check-hidden'"
                                    x-bind:style="'--reveal-delay: ' + (ci * 80) + 'ms'">
                                    <div class="w-10 flex-shrink-0 flex items-start gap-2">
                                        <div class="mt-0.5 h-5 min-w-[1.25rem] text-right text-xs font-semibold text-gray-500 dark:text-gray-400"
                                             x-text="ci + 1"></div>
                                        <div class="mt-0.5 h-5 w-5 flex items-center justify-center">
                                            <template x-if="check.result === 'PASS'">
                                                <svg class="h-4.5 w-4.5 text-success-500" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                            </template>
                                            <template x-if="check.result !== 'PASS'">
                                                <svg class="h-4.5 w-4.5 text-danger-500" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                            </template>
                                        </div>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-medium leading-snug"
                                             x-bind:class="check.result === 'PASS' ? 'text-gray-800 dark:text-gray-200' : 'text-danger-700 dark:text-danger-400'"
                                             x-text="check.description || check.item"></div>
                                        <template x-if="check.detail">
                                            <div class="mt-1 text-xs text-gray-400 dark:text-gray-500 font-mono break-all" x-text="check.detail"></div>
                                        </template>
                                        <template x-if="checkImageArtifact(check, ci)">
                                            <button type="button"
                                                    class="mt-3 block overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40"
                                                    x-on:click="openArtifact(checkImageArtifact(check, ci))">
                                                <img :src="checkImageArtifact(check, ci).url"
                                                     :alt="checkImageArtifact(check, ci).label"
                                                     class="w-full max-h-44 object-contain bg-white dark:bg-gray-950"
                                                     loading="lazy">
                                            </button>
                                        </template>
                                    </div>
                                </li>
                            </template>
                        </template>

                        {{-- Idle — no result yet, no run started --}}
                        <template x-if="!detailRunning && !detailResult && activeFile && activeFile.criteria && activeFile.criteria.length">
                            <template x-for="(criterion, ci) in activeFile.criteria" :key="'idle-' + ci">
                                <li class="px-5 py-3.5 flex items-start gap-3">
                                    <div class="w-10 flex-shrink-0 flex items-start gap-2">
                                        <div class="mt-0.5 h-5 min-w-[1.25rem] text-right text-xs font-semibold text-gray-400 dark:text-gray-500"
                                             x-text="ci + 1"></div>
                                        <div class="mt-0.5 h-5 w-5 flex items-center justify-center">
                                            <div class="h-4 w-4 rounded-full border-2 border-gray-200 dark:border-gray-700"></div>
                                        </div>
                                    </div>
                                    <span class="text-sm text-gray-400 dark:text-gray-500 leading-snug" x-text="criterion"></span>
                                </li>
                            </template>
                        </template>
                    </ul>

                    {{-- Error banner --}}
                    <template x-if="!detailRunning && detailResult && detailResult.error">
                        <div class="mx-5 mb-5 mt-3 rounded-xl bg-danger-50 dark:bg-danger-950/20 border border-danger-200 dark:border-danger-800 px-4 py-3">
                            <div class="text-xs font-semibold text-danger-600 dark:text-danger-400 uppercase tracking-wide mb-1">Error</div>
                            <div class="text-sm text-danger-700 dark:text-danger-300 font-mono break-all"
                                 x-text="detailResult.error"></div>
                        </div>
                    </template>
                </div>

                {{-- Artifacts + detail (right / larger column) --}}
                <div class="xl:col-span-3 space-y-5">

                    {{-- Artifacts --}}
                    <template x-if="!detailRunning && detailResult && detailResult.artifacts && detailResult.artifacts.length">
                        <div class="bg-white dark:bg-gray-800 rounded-2xl ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
                            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                                <div class="font-semibold text-gray-900 dark:text-white text-sm">Artifacts</div>
                            </div>
                            <div class="p-4 grid grid-cols-2 gap-3">
                                <template x-for="(artifact, ai) in detailResult.artifacts" :key="ai">
                                    <div class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden bg-gray-50 dark:bg-gray-900/40">
                                        <template x-if="artifact.kind === 'image'">
                                            <button type="button" class="block w-full" x-on:click="openArtifact(artifact)">
                                                <img :src="artifact.url" :alt="artifact.label" class="w-full h-40 object-contain bg-white dark:bg-gray-950" loading="lazy">
                                            </button>
                                        </template>
                                        <template x-if="artifact.kind !== 'image'">
                                            <div class="p-4 flex items-center justify-center h-20">
                                                <a :href="artifact.url" target="_blank" class="text-sm text-primary-600 dark:text-primary-400 underline" x-text="artifact.label"></a>
                                            </div>
                                        </template>
                                        <div class="px-3 py-2 border-t border-gray-200 dark:border-gray-700">
                                            <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"
                                                 x-text="artifact.label"></div>
                                            <template x-if="artifact.check_description">
                                                <div class="mt-1 text-xs leading-4 text-gray-600 dark:text-gray-300 line-clamp-3"
                                                     x-text="artifact.check_description"></div>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    {{-- Placeholder while running --}}
                    <template x-if="detailRunning">
                        <div class="bg-white dark:bg-gray-800 rounded-2xl ring-1 ring-gray-950/5 dark:ring-white/10 p-8 flex flex-col items-center justify-center gap-3 text-center min-h-[200px]">
                            <svg class="animate-spin h-8 w-8 text-danger-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                            <div class="text-sm text-gray-400 dark:text-gray-500">Screenshots and artifacts will appear here after the test completes.</div>
                        </div>
                    </template>

                    {{-- Idle placeholder --}}
                    <template x-if="!detailRunning && !detailResult">
                        <div class="bg-white dark:bg-gray-800 rounded-2xl ring-1 ring-gray-950/5 dark:ring-white/10 p-8 flex flex-col items-center justify-center gap-3 text-center min-h-[200px]">
                            <svg class="h-10 w-10 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M10 8l6 4-6 4V8z"/></svg>
                            <div class="text-sm text-gray-400 dark:text-gray-500">Press <strong class="text-gray-600 dark:text-gray-300">Run Test</strong> to start.</div>
                        </div>
                    </template>

                    {{-- Full check detail table (accessible once all checks revealed) --}}
                    <template x-if="!detailRunning && detailResult && revealIndex >= (detailResult.checks || []).length && detailResult.checks && detailResult.checks.length > 0">
                        <div class="bg-white dark:bg-gray-800 rounded-2xl ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
                            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                                <div class="font-semibold text-gray-900 dark:text-white text-sm">Check Details</div>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-xs">
                                    <thead class="bg-gray-50 dark:bg-gray-700/50">
                                        <tr>
                                            <th class="px-4 py-2.5 text-left font-medium text-gray-500 dark:text-gray-400 w-12">#</th>
                                            <th class="px-4 py-2.5 text-left font-medium text-gray-500 dark:text-gray-400 w-16">Result</th>
                                            <th class="px-4 py-2.5 text-left font-medium text-gray-500 dark:text-gray-400">Check</th>
                                            <th class="px-4 py-2.5 text-left font-medium text-gray-500 dark:text-gray-400 hidden md:table-cell">Detail</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                        <template x-for="(check, ci) in (detailResult.checks || [])" :key="'row-' + ci">
                                            <tr x-bind:class="check.result !== 'PASS' ? 'bg-danger-50/30 dark:bg-danger-950/10' : ''">
                                                <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400 font-semibold" x-text="ci + 1"></td>
                                                <td class="px-4 py-2.5">
                                                    <span class="inline-flex px-1.5 py-0.5 rounded text-xs font-semibold"
                                                          x-bind:class="check.result === 'PASS' ? 'bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400' : 'bg-danger-100 text-danger-700 dark:bg-danger-900/30 dark:text-danger-400'"
                                                          x-text="check.result">
                                                    </span>
                                                </td>
                                                <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300" x-text="check.description || check.item"></td>
                                                <td class="px-4 py-2.5 text-gray-400 dark:text-gray-500 font-mono break-all hidden md:table-cell" x-text="check.detail || '—'"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{-- ═══════════════════════════════════════════════════════════
             LIGHT-BOX (shared across screens)
        ═══════════════════════════════════════════════════════════ --}}
        <div x-show="activeArtifact" x-cloak
             class="fixed inset-0 z-[100] bg-black/80 p-6 flex items-center justify-center"
             x-on:click.self="closeArtifact()"
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100">
            <div class="max-w-6xl w-full bg-white dark:bg-gray-900 rounded-2xl overflow-hidden shadow-2xl">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between">
                    <div class="font-medium text-gray-900 dark:text-white text-sm" x-text="activeArtifact?.label || ''"></div>
                    <button type="button" x-on:click="closeArtifact()"
                            class="text-sm text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white px-3 py-1.5 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                        Close
                    </button>
                </div>
                <div class="p-4 bg-gray-100 dark:bg-gray-950">
                    <img x-show="activeArtifact && activeArtifact.kind === 'image'"
                         :src="activeArtifact?.url"
                         :alt="activeArtifact?.label || ''"
                         class="w-full max-h-[80vh] object-contain">
                </div>
            </div>
        </div>
    </div>

    <style>
        [x-cloak] { display: none !important; }

        .pdf-check-item { transition: opacity 200ms ease, transform 200ms ease; }
        .pdf-check-hidden { opacity: 0; transform: translateY(6px); pointer-events: none; }
        .pdf-check-revealed { opacity: 1; transform: translateY(0); pointer-events: auto; }
    </style>

    <script>
        function pdfTestRunner() {
            return {
                /* ── shared ── */
                loading: false,
                files: [],
                latestRunCreatedAt: null,
                activeArtifact: null,
                activeTab: 'upload-tests',
                activeCategory: 'all',
                uploadTests: [],
                uploadTestsLoading: false,
                uploading: false,
                uploadParagraphGrouping: false,
                deletingUploadTestId: null,
                updatingParagraphGroupingId: null,
                runningUploadTestCaseId: null,
                uploadBatchRunning: false,
                uploadBatchFinished: false,
                uploadBatchScope: null,
                uploadBatchTotal: 0,
                uploadBatchCompleted: 0,
                uploadBatchPassed: 0,
                uploadBatchFailed: 0,
                uploadBatchErrors: 0,
                uploadTestResults: {},
                uploadError: '',
                uploadSuccess: '',
                get uploadTestsBusy() {
                    return this.uploading
                        || this.uploadBatchRunning
                        || this.runningUploadTestCaseId !== null
                        || this.deletingUploadTestId !== null
                        || this.updatingParagraphGroupingId !== null;
                },
                get allUploadTestCasesCount() {
                    return (this.uploadTests || []).reduce(
                        (total, test) => total + (Array.isArray(test?.cases) ? test.cases.length : 0),
                        0
                    );
                },
                categoryName(file) {
                    return (file && file.test_category) ? file.test_category : 'PDF Tests';
                },
                get editorFiles() {
                    return (this.files || []).filter((f) => (f.editor || 'edit-new') === this.activeTab);
                },
                get visibleCategories() {
                    return Array.from(new Set(this.editorFiles.map((f) => this.categoryName(f)))).sort((a, b) => a.localeCompare(b));
                },
                get visibleFiles() {
                    return this.editorFiles.filter((f) => this.activeCategory === 'all' || this.categoryName(f) === this.activeCategory);
                },
                editorCount(tab) {
                    return (this.files || []).filter((f) => (f.editor || 'edit-new') === tab).length;
                },
                categoryCount(category) {
                    return this.editorFiles.filter((f) => this.categoryName(f) === category).length;
                },
                setActiveTab(tab) {
                    if (this.globalRunning) return;
                    this.activeTab = tab;
                    if (this.activeCategory !== 'all' && !this.visibleCategories.includes(this.activeCategory)) {
                        this.activeCategory = 'all';
                    }
                    try {
                        const url = new URL(window.location.href);
                        url.searchParams.set('editor', tab);
                        if (tab === 'upload-tests' || this.activeCategory === 'all') url.searchParams.delete('category');
                        else url.searchParams.set('category', this.activeCategory);
                        history.replaceState(history.state || {}, '', url.toString());
                    } catch (_e) { /* ignore */ }
                    if (tab === 'upload-tests') this.loadUploadTests();
                },
                setActiveCategory(category) {
                    if (this.globalRunning) return;
                    this.activeCategory = category || 'all';
                    try {
                        const url = new URL(window.location.href);
                        if (this.activeCategory === 'all') url.searchParams.delete('category');
                        else url.searchParams.set('category', this.activeCategory);
                        history.replaceState(history.state || {}, '', url.toString());
                    } catch (_e) { /* ignore */ }
                },

                /* ── list screen ── */
                screen: 'list',
                globalRunning: false,
                globalFinished: false,
                allRunResults: [],
                get allRunPassedCount() { return this.allRunResults.filter((r) => r.status === 'pass').length; },
                get allRunFailedCount() { return this.allRunResults.filter((r) => r.status === 'fail').length; },
                get allRunErrorCount()  { return this.allRunResults.filter((r) => r.status === 'error').length; },

                /* ── detail screen ── */
                activeFile: null,
                detailRunning: false,
                detailResult: null,
                fakeProgressIndex: 0,
                fakeProgressTimer: null,
                revealIndex: 0,
                revealTimer: null,

                /* ── per-member-test runs (shown when activeFile is a suite) ── */
                memberRunningKey: null,   // path of the currently-running member test, or null
                memberResults: {},        // map: member.path → normalized result
                get isSuite() {
                    return !!(this.activeFile && Array.isArray(this.activeFile.member_tests) && this.activeFile.member_tests.length);
                },

                async init() {
                    const params = new URLSearchParams(window.location.search);
                    const editorParam = params.get('editor');
                    if (editorParam === 'edit-new' || editorParam === 'upload-tests') {
                        this.activeTab = editorParam;
                    }

                    this.loading = true;
                    const uploadTestsPromise = this.loadUploadTests();
                    try {
                        const response = await fetch('{{ route('pdfTests.testFiles') }}', {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                        });
                        const data = await response.json();
                        if (!response.ok || !data.success) throw new Error(data.message || 'Failed to load tests');
                        this.files = (data.files || []).filter(
                            (file) => (file?.editor || 'edit-new') !== 'edit'
                        );

                        if (data.latest_run?.results?.length) {
                            this.allRunResults = data.latest_run.results.map((r) => this.normalizeResult(r));
                            this.latestRunCreatedAt = data.latest_run.created_at || null;
                        }
                    } catch (e) {
                        console.error(e);
                    } finally {
                        this.loading = false;
                    }
                    await uploadTestsPromise;

                    // Check if URL has a test param — open that test's detail screen
                    const categoryParam = params.get('category');
                    if (categoryParam && this.visibleCategories.includes(categoryParam)) {
                        this.activeCategory = categoryParam;
                    }
                    const testParam = params.get('test');
                    if (testParam && this.files.length) {
                        const match = this.files.find((f) => f.path === testParam);
                        if (match) {
                            // Make sure the test's editor tab is active so the
                            // user sees the right list when they close detail.
                            if (match.editor) this.activeTab = match.editor;
                            this.activeCategory = this.categoryName(match);
                            this.openTest(match);
                        }
                    }

                    // Handle browser back/forward
                    window.addEventListener('popstate', (event) => {
                        const state = event.state || {};
                        if (state.pdfTestScreen === 'detail' && state.testKey) {
                            const match = this.files.find((f) => f.path === state.testKey);
                            if (match) {
                                if (match.editor) this.activeTab = match.editor;
                                this.activeCategory = this.categoryName(match);
                                this.activeFile = match;
                                this.screen = 'detail';
                                return;
                            }
                        }
                        this.screen = 'list';
                        this.activeFile = null;
                    });
                },

                /* ── persistent PDF upload test harness ── */
                async loadUploadTests() {
                    if (this.uploadTestsLoading) return;
                    this.uploadTestsLoading = true;
                    try {
                        const response = await fetch('{{ route('pdfTests.uploadTests.index') }}', {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                        });
                        const data = await response.json();
                        if (!response.ok || !data.success) {
                            throw new Error(data.message || 'Failed to load PDF upload tests');
                        }
                        this.uploadTests = Array.isArray(data.tests) ? data.tests : [];
                    } catch (error) {
                        this.uploadError = error?.message || String(error);
                    } finally {
                        this.uploadTestsLoading = false;
                    }
                },

                async submitPdfUpload() {
                    if (this.uploading) return;
                    const file = this.$refs.uploadTestPdf?.files?.[0];
                    if (!file) {
                        this.uploadError = 'Choose a PDF file first.';
                        return;
                    }

                    this.uploading = true;
                    this.uploadError = '';
                    this.uploadSuccess = '';
                    const body = new FormData();
                    body.append('pdf', file);
                    body.append('paragraph_grouping_enabled', this.uploadParagraphGrouping ? '1' : '0');

                    try {
                        const response = await fetch('{{ route('pdfTests.uploadTests.store') }}', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                'Accept': 'application/json',
                            },
                            body,
                        });
                        const data = await response.json();
                        if (!response.ok || !data.success) {
                            const validationMessage = data.errors?.pdf?.[0];
                            throw new Error(validationMessage || data.message || 'PDF upload failed');
                        }

                        this.uploadSuccess = data.message || 'PDF upload test created.';
                        if (this.$refs.uploadTestPdf) this.$refs.uploadTestPdf.value = '';
                        if (data.test?.review_url) {
                            window.location.assign(data.test.review_url);
                            return;
                        }
                        await this.loadUploadTests();
                    } catch (error) {
                        this.uploadError = error?.message || String(error);
                    } finally {
                        this.uploading = false;
                    }
                },

                async deleteUploadTest(test) {
                    if (!test?.id || !test?.delete_url || this.uploadTestsBusy) return;
                    const name = String(test.original_name || 'this PDF');
                    if (!window.confirm(`Delete “${name}”? This permanently removes the uploaded PDF and all of its saved annotation tests.`)) {
                        return;
                    }

                    this.deletingUploadTestId = test.id;
                    this.uploadError = '';
                    this.uploadSuccess = '';
                    try {
                        const response = await fetch(test.delete_url, {
                            method: 'DELETE',
                            credentials: 'same-origin',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                'Accept': 'application/json',
                            },
                        });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok || !data.success) {
                            throw new Error(data.message || 'The uploaded PDF could not be deleted.');
                        }

                        this.uploadTests = this.uploadTests.filter((item) => item.id !== test.id);
                        for (const testCase of (test.cases || [])) {
                            delete this.uploadTestResults[testCase.id];
                        }
                        this.uploadSuccess = data.message || 'Uploaded PDF deleted.';
                    } catch (error) {
                        this.uploadError = error?.message || String(error);
                    } finally {
                        this.deletingUploadTestId = null;
                    }
                },

                async updateUploadParagraphGrouping(test, enabled) {
                    if (!test?.id || !test?.paragraph_grouping_url || this.uploadTestsBusy) return;

                    const previous = Boolean(test.paragraph_grouping_enabled);
                    test.paragraph_grouping_enabled = Boolean(enabled);
                    this.updatingParagraphGroupingId = test.id;
                    this.uploadError = '';
                    this.uploadSuccess = '';
                    try {
                        const response = await fetch(test.paragraph_grouping_url, {
                            method: 'PATCH',
                            credentials: 'same-origin',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({
                                paragraph_grouping_enabled: Boolean(enabled),
                            }),
                        });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok || !data.success) {
                            throw new Error(data.message || 'Paragraph grouping could not be updated.');
                        }

                        test.paragraph_grouping_enabled = Boolean(
                            data.test?.paragraph_grouping_enabled
                        );
                        this.uploadSuccess = data.message || 'Paragraph grouping updated.';
                    } catch (error) {
                        test.paragraph_grouping_enabled = previous;
                        this.uploadError = error?.message || String(error);
                    } finally {
                        this.updatingParagraphGroupingId = null;
                    }
                },

                async runUploadTest(test, testCase) {
                    if (!test?.id || !testCase?.id || this.uploadTestsBusy) return;
                    this.uploadBatchFinished = false;
                    await this.executeUploadTest(test, testCase);
                },

                async executeUploadTest(test, testCase) {
                    this.runningUploadTestCaseId = testCase.id;
                    this.uploadError = '';
                    this.uploadSuccess = '';
                    delete this.uploadTestResults[testCase.id];
                    let result;
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
                                test_key: 'pdf_upload_saved_test',
                                upload_test_id: test.id,
                                upload_test_case_id: testCase.id,
                                run_id: this.nextRunId(),
                            }),
                        });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok || !data.success) {
                            throw new Error(data.message || 'The uploaded PDF test could not run.');
                        }

                        result = this.normalizeResult(data.result || {});
                    } catch (error) {
                        result = this.normalizeResult({
                            test_key: `pdf_upload_test_${test.id}_case_${testCase.id}`,
                            filename: test.original_name || `pdf-upload-test-${test.id}.pdf`,
                            status: 'error',
                            checks_passed: 0,
                            checks_total: 0,
                            checks: [],
                            error: error?.message || String(error),
                            warnings: [],
                            artifacts: [],
                        });
                    } finally {
                        this.uploadTestResults[testCase.id] = result;
                        this.runningUploadTestCaseId = null;
                    }
                    return result;
                },

                async runAllUploadTestsForPdf(test) {
                    const entries = (Array.isArray(test?.cases) ? test.cases : [])
                        .map((testCase) => ({ test, testCase }));
                    await this.runUploadTestBatch(entries, `pdf:${test?.id || ''}`);
                },

                async runAllUploadedPdfTests() {
                    const entries = [];
                    for (const test of (this.uploadTests || [])) {
                        for (const testCase of (Array.isArray(test?.cases) ? test.cases : [])) {
                            entries.push({ test, testCase });
                        }
                    }
                    await this.runUploadTestBatch(entries, 'all');
                },

                async runUploadTestBatch(entries, scope) {
                    if (this.uploadTestsBusy || !Array.isArray(entries) || entries.length === 0) return;

                    this.uploadBatchRunning = true;
                    this.uploadBatchFinished = false;
                    this.uploadBatchScope = scope;
                    this.uploadBatchTotal = entries.length;
                    this.uploadBatchCompleted = 0;
                    this.uploadBatchPassed = 0;
                    this.uploadBatchFailed = 0;
                    this.uploadBatchErrors = 0;
                    this.uploadError = '';
                    this.uploadSuccess = '';

                    try {
                        for (const entry of entries) {
                            const result = await this.executeUploadTest(entry.test, entry.testCase);
                            this.uploadBatchCompleted += 1;
                            if (result?.status === 'pass') this.uploadBatchPassed += 1;
                            else if (result?.status === 'fail') this.uploadBatchFailed += 1;
                            else this.uploadBatchErrors += 1;
                        }
                        this.uploadSuccess = `Completed ${this.uploadBatchCompleted} uploaded PDF tests.`;
                    } finally {
                        this.uploadBatchRunning = false;
                        this.uploadBatchFinished = true;
                        this.runningUploadTestCaseId = null;
                    }
                },

                uploadResultDetailsUrl(result) {
                    if (!result?.id) {
                        return '{{ \App\Filament\Resources\OverlayEditorTestResource::getUrl() }}';
                    }
                    return '{{ \App\Filament\Resources\OverlayEditorTestResource::getUrl('view', ['record' => '__RECORD__']) }}'
                        .replace('__RECORD__', encodeURIComponent(result.id));
                },

                /* ── navigation ── */
                openTest(file) {
                    this.activeFile = file;
                    this.detailRunning = false;
                    this.detailResult = this.latestResultFor(file.path) || null;
                    this.revealIndex = this.detailResult ? (this.detailResult.checks || []).length : 0;
                    this.fakeProgressIndex = 0;
                    // Reset per-member state when opening a new (potentially suite) test.
                    this.memberRunningKey = null;
                    this.memberResults = {};
                    this.screen = 'detail';
                    const url = new URL(window.location.href);
                    url.searchParams.set('test', file.path);
                    history.pushState({ pdfTestScreen: 'detail', testKey: file.path }, '', url.toString());
                },

                openAndRunTest(file) {
                    this.openTest(file);
                    this.detailResult = null;
                    this.revealIndex = 0;
                    this.$nextTick(() => this.runDetailTest());
                },

                closeTest() {
                    this.stopFakeProgress();
                    this.stopReveal();
                    this.screen = 'list';
                    this.activeFile = null;
                    const url = new URL(window.location.href);
                    url.searchParams.delete('test');
                    history.pushState({ pdfTestScreen: 'list' }, '', url.toString());
                },

                /* ── run all ── */
                async startAllTests() {
                    if (this.globalRunning || !this.visibleFiles.length) return;
                    this.globalRunning = true;
                    this.globalFinished = false;
                    this.allRunResults = [];
                    const runId = this.nextRunId();

                    for (const file of this.visibleFiles) {
                        const result = await this.runTestRequest(file, runId);
                        this.allRunResults = [...this.allRunResults, result];
                    }

                    this.globalRunning = false;
                    this.globalFinished = true;
                },

                /* ── run single on detail screen ── */
                async runDetailTest() {
                    if (!this.activeFile || this.detailRunning) return;
                    this.detailRunning = true;
                    this.detailResult = null;
                    this.revealIndex = 0;
                    this.startFakeProgress();

                    const result = await this.runTestRequest(this.activeFile, this.nextRunId());

                    this.stopFakeProgress();
                    this.detailResult = result;

                    // Also update allRunResults so list screen reflects new result
                    const existingIdx = this.allRunResults.findIndex((r) => r.test_key === result.test_key);
                    if (existingIdx >= 0) {
                        this.allRunResults = this.allRunResults.map((r, i) => i === existingIdx ? result : r);
                    } else {
                        this.allRunResults = [...this.allRunResults, result];
                    }

                    this.detailRunning = false;
                    this.startReveal(result.checks || []);
                },

                /* ── run a single member test inside an open suite ── */
                async runMemberTest(member) {
                    if (!member || !this.isSuite) return;
                    if (this.memberRunningKey || this.detailRunning) return;
                    this.memberRunningKey = member.path;
                    const result = await this.runTestRequest(member, this.nextRunId());
                    this.memberResults = { ...this.memberResults, [member.path]: result };
                    this.memberRunningKey = null;
                },

                memberStatus(member) {
                    if (!member) return null;
                    return this.memberResults[member.path] || null;
                },
                memberStatusLabel(member) {
                    const r = this.memberStatus(member);
                    if (!r) return '—';
                    if (r.status === 'pass') return 'PASS';
                    if (r.status === 'fail') return `FAIL (${r.checks_passed}/${r.checks_total})`;
                    return (r.status || 'error').toUpperCase();
                },
                memberStatusClass(member) {
                    const r = this.memberStatus(member);
                    if (!r) return 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400';
                    if (r.status === 'pass') return 'bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400';
                    if (r.status === 'fail') return 'bg-danger-100 text-danger-700 dark:bg-danger-900/30 dark:text-danger-400';
                    return 'bg-warning-100 text-warning-700 dark:bg-warning-900/30 dark:text-warning-400';
                },

                /* ── fake progress animation ── */
                startFakeProgress() {
                    this.fakeProgressIndex = 0;
                    this.stopFakeProgress();
                    const total = (this.activeFile?.criteria || []).length || 1;
                    this.fakeProgressTimer = setInterval(() => {
                        if (this.fakeProgressIndex < total - 1) {
                            this.fakeProgressIndex += 1;
                        }
                    }, Math.max(600, Math.min(2000, 180000 / total))); // spread across ~3 min max
                },

                stopFakeProgress() {
                    if (this.fakeProgressTimer) { clearInterval(this.fakeProgressTimer); this.fakeProgressTimer = null; }
                },

                /* ── reveal checks one by one ── */
                startReveal(checks) {
                    this.revealIndex = 0;
                    this.stopReveal();
                    if (!checks.length) return;
                    this.revealTimer = setInterval(() => {
                        if (this.revealIndex < checks.length) {
                            this.revealIndex += 1;
                        } else {
                            this.stopReveal();
                        }
                    }, 100);
                },

                stopReveal() {
                    if (this.revealTimer) { clearInterval(this.revealTimer); this.revealTimer = null; }
                },

                /* ── helpers ── */
                latestResultFor(testKey) {
                    return this.allRunResults.find((r) => r.test_key === testKey) || null;
                },

                checkImageArtifact(check, checkIndex) {
                    if (!this.detailResult?.artifacts?.length || !check) return null;
                    return this.detailResult.artifacts.find((artifact) => (
                        artifact.kind === 'image'
                        && (
                            artifact.check_item === check.item
                            || Number(artifact.check_index) === (checkIndex + 1)
                        )
                    )) || null;
                },

                async runTestRequest(file, runId) {
                    try {
                        const response = await fetch('{{ route('pdfTests.runSingleTest') }}', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({ test_key: file.path, run_id: runId }),
                        });
                        const data = await response.json();
                        if (!response.ok || !data.success) throw new Error(data.message || 'Test failed');
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

                normalizeResult(result) {
                    const testKey = result?.test_key || String(result?.filename || '').replace(/\.pdf$/i, '');
                    const artifactBase = '{{ route('pdfTests.artifact', ['filename' => '__FILENAME__']) }}'.replace('__FILENAME__', '');
                    return {
                        ...result,
                        test_key: testKey,
                        checks: Array.isArray(result?.checks) ? result.checks : [],
                        warnings: Array.isArray(result?.warnings) ? result.warnings : [],
                        artifacts: (Array.isArray(result?.artifacts) ? result.artifacts : []).map((a) => ({
                            ...a,
                            url: a.url || (artifactBase + encodeURIComponent(a.filename || '')),
                        })),
                    };
                },

                nextRunId() {
                    if (window.crypto?.randomUUID) return window.crypto.randomUUID();
                    return `pdf-run-${Date.now()}-${Math.random().toString(16).slice(2)}`;
                },

                formatTimestamp(value) {
                    if (!value) return 'unknown';
                    const d = new Date(value);
                    if (Number.isNaN(d.getTime())) return value;
                    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(d);
                },

                formatBytes(value) {
                    const bytes = Number(value || 0);
                    if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
                    const units = ['B', 'KB', 'MB', 'GB'];
                    const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
                    const amount = bytes / Math.pow(1024, index);
                    return `${amount.toFixed(index === 0 ? 0 : 1)} ${units[index]}`;
                },

                openArtifact(artifact) { this.activeArtifact = artifact; },
                closeArtifact()        { this.activeArtifact = null; },
            };
        }
    </script>
</x-filament-panels::page>
