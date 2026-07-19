<div id="enpv-merge-modal" class="enpv-convert-modal enpv-merge-modal" hidden>
    <section class="enpv-convert-card enpv-merge-card" role="dialog" aria-modal="true" aria-labelledby="enpv-merge-title" aria-describedby="enpv-merge-subtitle">
        <header class="enpv-convert-header">
            <div class="enpv-convert-heading">
                <div class="enpv-convert-icon" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M8 3H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2v-2"></path>
                        <path d="M16 5h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-8a2 2 0 0 1-2-2v-2"></path>
                        <path d="M12 3v10"></path><path d="m8 9 4 4 4-4"></path>
                    </svg>
                </div>
                <div>
                    <h2 id="enpv-merge-title">Merge / Split PDFs</h2>
                    <p id="enpv-merge-subtitle">Combine documents or extract selected pages.</p>
                </div>
            </div>
            <button id="enpv-merge-close" class="enpv-icon-button" type="button" title="Close" aria-label="Close merge or split tool">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"></path></svg>
            </button>
        </header>

        <div class="enpv-merge-split-tabs" role="tablist" aria-label="Merge or split PDF">
            <button id="enpv-merge-tab" type="button" role="tab" aria-selected="true" aria-controls="enpv-merge-panel" class="is-active">Merge</button>
            <button id="enpv-split-tab" type="button" role="tab" aria-selected="false" aria-controls="enpv-split-panel">Split</button>
        </div>

        <div id="enpv-merge-panel" class="enpv-merge-split-panel" role="tabpanel" aria-labelledby="enpv-merge-tab">
            <main class="enpv-merge-body">
                <input id="enpv-merge-file-input" type="file" accept="application/pdf,.pdf" multiple hidden>
                <button id="enpv-merge-dropzone" class="enpv-merge-dropzone" type="button">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"></path><path d="m7 8 5-5 5 5"></path><path d="M5 21h14a2 2 0 0 0 2-2v-4"></path><path d="M3 15v4a2 2 0 0 0 2 2"></path></svg>
                    <span><strong>Add PDF files</strong><small>Choose files or drop them here</small></span>
                </button>

                <div class="enpv-merge-order-heading">
                    <div>
                        <strong>Document order</strong>
                        <small>Each PDF moves as one complete document. Pages cannot be reordered here.</small>
                    </div>
                    <span id="enpv-merge-summary">1 PDF</span>
                </div>
                <div id="enpv-merge-list" class="enpv-merge-list" aria-label="PDF document order"></div>
                <div id="enpv-merge-status" class="enpv-merge-status" role="status" aria-live="polite"></div>
            </main>

            <footer class="enpv-convert-footer enpv-merge-footer">
                <span>The current working PDF will be updated.</span>
                <div class="enpv-convert-actions">
                    <button id="enpv-merge-cancel" type="button" class="enpv-secondary-button">Cancel</button>
                    <button id="enpv-merge-submit" type="button" class="enpv-primary-button" disabled>Merge PDFs</button>
                </div>
            </footer>
        </div>

        <div id="enpv-split-panel" class="enpv-merge-split-panel" role="tabpanel" aria-labelledby="enpv-split-tab" hidden>
            <main class="enpv-merge-body enpv-split-body">
                <div class="enpv-split-toolbar">
                    <div>
                        <strong>Select pages</strong>
                        <small>Checked pages will be copied in their original order.</small>
                    </div>
                    <div class="enpv-split-toolbar-actions">
                        <button id="enpv-split-select-all" type="button">Select all</button>
                        <button id="enpv-split-clear" type="button">Clear selection</button>
                    </div>
                </div>
                <div id="enpv-split-grid" class="enpv-split-grid" aria-label="Pages to include in the split PDF"></div>
                <div id="enpv-split-status" class="enpv-merge-status" role="status" aria-live="polite"></div>
            </main>

            <footer class="enpv-convert-footer enpv-merge-footer enpv-split-footer">
                <strong id="enpv-split-summary">0 pages selected</strong>
                <div class="enpv-convert-actions">
                    <button id="enpv-split-cancel" type="button" class="enpv-secondary-button">Cancel</button>
                    <button id="enpv-split-submit" type="button" class="enpv-primary-button" disabled>Split selected pages</button>
                </div>
            </footer>
        </div>
    </section>

    <div id="enpv-split-name-dialog" class="enpv-split-name-overlay" hidden>
        <section class="enpv-split-name-card" role="dialog" aria-modal="true" aria-labelledby="enpv-split-name-title">
            <h3 id="enpv-split-name-title">Create split PDF</h3>
            <p>Choose a filename, then download the split PDF or open it in the editor.</p>
            <label for="enpv-split-name-input">Filename</label>
            <input id="enpv-split-name-input" type="text" maxlength="240" autocomplete="off" spellcheck="false">
            <div id="enpv-split-name-status" class="enpv-split-name-status" role="status" aria-live="polite"></div>
            <div class="enpv-convert-actions enpv-split-destination-actions">
                <button id="enpv-split-name-cancel" type="button" class="enpv-secondary-button">Cancel</button>
                <button id="enpv-split-name-download" type="button" class="enpv-secondary-button">Split &amp; download</button>
                <button id="enpv-split-name-open-editor" type="button" class="enpv-primary-button">Open in editor</button>
            </div>
        </section>
    </div>
</div>
