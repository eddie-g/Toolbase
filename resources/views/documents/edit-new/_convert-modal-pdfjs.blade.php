<div id="enpv-convert-modal" class="enpv-convert-modal" hidden>
    <div class="enpv-convert-card" role="dialog" aria-modal="true" aria-labelledby="enpv-convert-title">
        <header class="enpv-convert-header">
            <div class="enpv-convert-heading">
                <div id="enpv-convert-icon" class="enpv-convert-icon" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2 1.586-1.586a2 2 0 012.828 0L20 14"/><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/></svg>
                </div>
                <div>
                    <h2 id="enpv-convert-title">Export to Images</h2>
                    <p id="enpv-convert-subtitle">Convert PDF pages to image files</p>
                </div>
            </div>
            <button id="enpv-convert-close" class="enpv-icon-button" type="button" title="Close" aria-label="Close">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </header>

        <nav class="enpv-convert-tabs" aria-label="Convert options">
            <button type="button" class="enpv-convert-tab is-active" data-enpv-convert-tab="images">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2 1.586-1.586a2 2 0 012.828 0L20 14"/><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
                Images
            </button>
            <button type="button" class="enpv-convert-tab" data-enpv-convert-tab="pdfa">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15l2 2 4-4"/></svg>
                PDF/A
            </button>
            <button type="button" class="enpv-convert-tab" data-enpv-convert-tab="word">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M7 13h10M7 17h6"/></svg>
                Word
            </button>
            <button type="button" class="enpv-convert-tab" data-enpv-convert-tab="excel">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="3" x2="15" y2="21"/></svg>
                Excel
            </button>
        </nav>

        <main class="enpv-convert-body">
            <section id="enpv-convert-images" class="enpv-convert-panel">
                <div class="enpv-field-group">
                    <label>Image Format</label>
                    <div class="enpv-choice-grid enpv-choice-grid-3">
                        <button type="button" class="enpv-choice is-active" data-enpv-format="jpg"><strong>JPG</strong><span>Compressed</span></button>
                        <button type="button" class="enpv-choice" data-enpv-format="png"><strong>PNG</strong><span>Lossless</span></button>
                        <button type="button" class="enpv-choice" data-enpv-format="tiff"><strong>TIFF</strong><span>Print</span></button>
                    </div>
                </div>
                <div class="enpv-field-group">
                    <label>Pages</label>
                    <div class="enpv-choice-grid enpv-choice-grid-3">
                        <button type="button" class="enpv-choice is-active" data-enpv-pages="all">All Pages</button>
                        <button type="button" class="enpv-choice" data-enpv-pages="range">Page Range</button>
                        <button type="button" class="enpv-choice" data-enpv-pages="custom">Custom</button>
                    </div>
                    <div id="enpv-convert-range" class="enpv-inline-fields" hidden>
                        <label>From <input id="enpv-convert-page-from" type="number" min="1" value="1"></label>
                        <label>To <input id="enpv-convert-page-to" type="number" min="1" value="1"></label>
                    </div>
                    <div id="enpv-convert-custom-wrap" class="enpv-stacked-field" hidden>
                        <input id="enpv-convert-page-custom" type="text" placeholder="e.g. 1, 3, 5-8, 12">
                        <span>Enter page numbers or ranges separated by commas.</span>
                    </div>
                </div>
                <div class="enpv-convert-two-col">
                    <div class="enpv-field-group">
                        <label>Resolution (DPI)</label>
                        <div class="enpv-range-row">
                            <input id="enpv-convert-dpi" type="range" min="12" max="2400" value="150" step="1">
                            <input id="enpv-convert-dpi-value" type="number" min="12" max="2400" value="150">
                        </div>
                        <div class="enpv-preset-row"><button type="button" data-enpv-dpi="72">72</button><button type="button" data-enpv-dpi="150">150</button><button type="button" data-enpv-dpi="300">300</button><button type="button" data-enpv-dpi="600">600</button></div>
                    </div>
                    <div class="enpv-field-group">
                        <label>Color Model</label>
                        <select id="enpv-convert-color-model">
                            <option value="rgb">RGB — Screen / Web</option>
                            <option value="rgba">RGBA — With Transparency</option>
                            <option value="grayscale">Grayscale</option>
                            <option value="cmyk">CMYK — Print</option>
                            <option value="lab">Lab — Perceptual</option>
                        </select>
                        <span id="enpv-convert-color-hint" class="enpv-help-text">Best for digital screens and web use</span>
                    </div>
                </div>
                <div class="enpv-convert-two-col">
                    <div id="enpv-convert-quality-wrap" class="enpv-field-group">
                        <label>JPG Quality</label>
                        <div class="enpv-range-row"><input id="enpv-convert-quality" type="range" min="1" max="100" value="92"><output id="enpv-convert-quality-value">92</output></div>
                        <span class="enpv-help-text">Smaller file to better quality</span>
                    </div>
                    <div class="enpv-field-group">
                        <label>Image Smoothing</label>
                        <label class="enpv-checkbox-row"><input id="enpv-convert-smoothing" type="checkbox" checked> Anti-aliasing</label>
                        <span class="enpv-help-text">Smooths edges for a cleaner look.</span>
                    </div>
                </div>
            </section>

            <section id="enpv-convert-pdfa" class="enpv-convert-panel" hidden>
                <div class="enpv-info-box is-green"><strong>PDF/A Archival Format</strong><span>PDF/A embeds fonts, removes external dependencies, and stores metadata for long-term preservation.</span></div>
                <div class="enpv-field-group"><label>Conformance Level</label><select id="enpv-convert-pdfa-level"><option value="1b">PDF/A-1b — Basic (ISO 19005-1)</option><option value="2b" selected>PDF/A-2b — Recommended (ISO 19005-2)</option><option value="3b">PDF/A-3b — With Attachments (ISO 19005-3)</option></select><span class="enpv-help-text">PDF/A-2b is recommended for most archival purposes.</span></div>
                <div class="enpv-field-group"><label>Options</label><label class="enpv-option-card"><input id="enpv-convert-pdfa-embed-fonts" type="checkbox" checked><span><strong>Embed All Fonts</strong><small>Subset and embed all fonts used in the document.</small></span></label><label class="enpv-option-card"><input id="enpv-convert-pdfa-srgb" type="checkbox" checked><span><strong>sRGB Color Profile</strong><small>Attach sRGB ICC output intent for color consistency.</small></span></label></div>
            </section>

            <section id="enpv-convert-word" class="enpv-convert-panel" hidden>
                <div class="enpv-info-box"><strong>Word Document (.docx)</strong><span>Converts your PDF into an editable Word document with preserved layout where possible.</span></div>
                <div class="enpv-field-group"><label>Layout Mode</label><select id="enpv-convert-word-layout"><option value="flow">Flowing Text — Best for editing</option><option value="exact" selected>Exact Layout — Preserves positioning</option></select></div>
                <div class="enpv-field-group"><label>Options</label><label class="enpv-option-card"><input id="enpv-convert-word-images" type="checkbox" checked><span><strong>Include Images</strong><small>Embed images from the PDF into the Word document.</small></span></label><label class="enpv-option-card"><input id="enpv-convert-word-ocr" type="checkbox"><span><strong>OCR Scanned Pages</strong><small>Use OCR for pages that contain scanned images instead of text.</small></span></label></div>
            </section>

            <section id="enpv-convert-excel" class="enpv-convert-panel" hidden>
                <div class="enpv-info-box is-green"><strong>Excel Spreadsheet (.xlsx)</strong><span>Extracts tables and structured data from your PDF into a spreadsheet.</span></div>
                <div class="enpv-field-group"><label>Extraction Mode</label><select id="enpv-convert-excel-mode"><option value="tables" selected>Tables Only — Extract detected tables</option><option value="all">All Content — Extract all text into cells</option></select></div>
                <div class="enpv-field-group"><label>Options</label><label class="enpv-option-card"><input id="enpv-convert-excel-merge-cells" type="checkbox" checked><span><strong>Detect Merged Cells</strong><small>Identify and preserve merged cell regions from tables.</small></span></label><label class="enpv-option-card"><input id="enpv-convert-excel-sheet-per-page" type="checkbox" checked><span><strong>Separate Sheet per Page</strong><small>Create a worksheet for each PDF page.</small></span></label></div>
            </section>

            <div id="enpv-convert-progress" class="enpv-convert-progress" hidden>
                <div><span id="enpv-convert-progress-label">Exporting...</span><span id="enpv-convert-progress-pct">0%</span></div>
                <div class="enpv-progress-track"><div id="enpv-convert-progress-bar"></div></div>
            </div>
        </main>

        <footer class="enpv-convert-footer">
            <div id="enpv-convert-page-count">All pages will be exported</div>
            <div class="enpv-convert-actions"><button id="enpv-convert-cancel" type="button" class="enpv-secondary-button">Cancel</button><button id="enpv-convert-export" type="button" class="enpv-primary-button">Export</button></div>
        </footer>
    </div>
</div>

<div id="enpv-pdfa-report-modal" class="enpv-convert-modal" hidden>
    <div class="enpv-pdfa-report-card" role="dialog" aria-modal="true" aria-labelledby="enpv-pdfa-report-title">
        <header class="enpv-pdfa-report-header"><div id="enpv-pdfa-report-icon" class="enpv-pdfa-report-icon"></div><div><h2 id="enpv-pdfa-report-title"></h2><p id="enpv-pdfa-report-subtitle"></p></div></header>
        <div class="enpv-pdfa-report-meta"><span id="enpv-pdfa-report-pages"></span><span id="enpv-pdfa-report-size"></span><span id="enpv-pdfa-report-time"></span></div>
        <div id="enpv-pdfa-report-checks" class="enpv-pdfa-report-checks"></div>
        <footer class="enpv-convert-footer"><div id="enpv-pdfa-report-summary"></div><div class="enpv-convert-actions"><button id="enpv-pdfa-report-close" type="button" class="enpv-secondary-button">Close</button><button id="enpv-pdfa-report-download" type="button" class="enpv-primary-button is-green">Download PDF/A</button></div></footer>
    </div>
</div>