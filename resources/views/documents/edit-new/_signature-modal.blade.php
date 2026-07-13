<div class="signature-modal" id="signature-modal" aria-hidden="true">
    <div class="signature-modal__scrim" id="signature-modal-scrim"></div>
    <div class="signature-modal__card" role="dialog" aria-modal="true" aria-labelledby="signature-modal-title">
        <div class="signature-modal__header">
            <div>
                <p class="signature-modal__eyebrow">Sign Document</p>
                <h2 class="signature-modal__title" id="signature-modal-title">Add your signature</h2>
                <p class="signature-modal__subtitle">Create a clean signature mark for this PDF. Draw it freehand, type it in a script style, or upload an existing signature image.</p>
            </div>
            <button type="button" class="signature-modal__close" id="signature-modal-close" aria-label="Close signature modal">×</button>
        </div>
        <div class="signature-modal__tabs">
            <button type="button" class="signature-modal__tab is-active" data-signature-mode="draw">Draw</button>
            <button type="button" class="signature-modal__tab" data-signature-mode="type">Type</button>
            <button type="button" class="signature-modal__tab" data-signature-mode="upload">Upload</button>
        </div>
        <div class="signature-modal__body">
            <div class="signature-modal__sidebar">
                <div class="signature-modal__panel is-active" data-signature-panel="draw">
                    <h3 class="signature-modal__panel-title">Draw naturally</h3>
                    <p class="signature-modal__panel-copy">Use your mouse, trackpad, or stylus to sketch a handwritten signature.</p>
                    <div class="signature-field">
                        <span class="signature-field__label">Ink color</span>
                        <label class="signature-color-chip">
                            <input id="signature-color" type="color" value="#111827" aria-label="Signature ink color">
                            <span class="signature-color-chip__value" id="signature-color-value">#111827</span>
                        </label>
                    </div>
                    <div class="signature-field">
                        <span class="signature-field__label">Stroke width</span>
                        <div class="signature-field__row">
                            <input id="signature-width" type="range" min="1" max="8" step="1" value="3" aria-label="Signature stroke width">
                            <span class="signature-slider-readout" id="signature-width-value">3px</span>
                        </div>
                    </div>
                    <div class="signature-field">
                        <span class="signature-field__label">Stroke smoothing</span>
                        <div class="signature-field__row signature-field__row--stack">
                            <div class="signature-slider-stack">
                                <input id="signature-smoothing" type="range" min="0" max="100" step="1" value="58" aria-label="Signature stroke smoothing">
                                <span class="signature-slider-readout" id="signature-smoothing-value">58%</span>
                            </div>
                            <span class="signature-stage__hint">Lower keeps the raw stroke. Higher softens corners.</span>
                        </div>
                    </div>
                </div>
                <div class="signature-modal__panel" data-signature-panel="type">
                    <h3 class="signature-modal__panel-title">Type a signature</h3>
                    <p class="signature-modal__panel-copy">Enter your name and preview it as a signature mark before placing it on the page.</p>
                    <label class="signature-field">
                        <span class="signature-field__label">Signature text</span>
                        <input id="signature-text" type="text" placeholder="Type your full name">
                    </label>
                    <label class="signature-field">
                        <span class="signature-field__label">Style</span>
                        <select id="signature-font">
                            <option value="Great Vibes" selected>Great Vibes</option>
                            <option value="Dancing Script">Dancing Script</option>
                            <option value="Allura">Allura</option>
                            <option value="Pacifico">Pacifico</option>
                            <option value="Alex Brush">Alex Brush</option>
                            <option value="Sacramento">Sacramento</option>
                            <option value="Parisienne">Parisienne</option>
                            <option value="Marck Script">Marck Script</option>
                            <option value="Satisfy">Satisfy</option>
                            <option value="Caveat">Caveat</option>
                            <option value="Kaushan Script">Kaushan Script</option>
                            <option value="Tangerine">Tangerine</option>
                        </select>
                    </label>
                    <div class="signature-field">
                        <span class="signature-field__label">Ink color</span>
                        <label class="signature-color-chip">
                            <input id="signature-type-color" type="color" value="#111827" aria-label="Typed signature color">
                            <span class="signature-color-chip__value" id="signature-type-color-value">#111827</span>
                        </label>
                    </div>
                    <div class="signature-field">
                        <span class="signature-field__label">Font size</span>
                        <div class="signature-field__row">
                            <input id="signature-type-size" type="range" min="48" max="180" step="2" value="136" aria-label="Typed signature font size">
                            <span class="signature-slider-readout" id="signature-type-size-value">136px</span>
                        </div>
                    </div>
                </div>
                <div class="signature-modal__panel" data-signature-panel="upload">
                    <h3 class="signature-modal__panel-title">Use an image</h3>
                    <p class="signature-modal__panel-copy">Import a scanned signature, transparent PNG, or any image file and stamp it into the PDF as an annotation.</p>
                    <label class="signature-upload">
                        <input id="signature-image-input" type="file" accept="image/*">
                        <span class="signature-upload__icon" aria-hidden="true">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                <polyline points="17 8 12 3 7 8"></polyline>
                                <line x1="12" y1="3" x2="12" y2="15"></line>
                            </svg>
                        </span>
                        <span class="signature-upload__title">Choose an image</span>
                        <span class="signature-upload__copy">PNG, JPG, WebP, GIF, and SVG are normalized to a stamped PNG asset for export.</span>
                        <span class="signature-upload__meta" id="signature-image-name">No file selected</span>
                    </label>
                </div>
                <div class="signature-library">
                    <div class="signature-library__header">
                        <h3 class="signature-library__title">Saved signatures</h3>
                        <p class="signature-library__copy">Save the current preview and reload it later in this browser.</p>
                    </div>
                    <div class="signature-library__load-row">
                        <select id="signature-library-select" class="signature-library__select" aria-label="Saved signatures">
                            <option value="">Load a saved signature</option>
                        </select>
                        <button type="button" class="signature-library__cta signature-library__load-btn" id="signature-library-load-btn" disabled>Load</button>
                    </div>
                    <div class="signature-library__save-row">
                        <input id="signature-save-name" class="signature-library__name" type="text" placeholder="Signature name">
                        <button type="button" class="signature-library__cta signature-library__save-btn" id="signature-save-btn" disabled>Save current</button>
                    </div>
                    <div class="signature-library__list" id="signature-library-list">
                        <div class="signature-library__empty">No saved signatures yet.</div>
                    </div>
                </div>
            </div>
            <div class="signature-stage">
                <div class="signature-stage__header">
                    <div>
                        <h3 class="signature-stage__title">Live preview</h3>
                        <p class="signature-stage__copy">This preview becomes the annotation that gets placed into the document.</p>
                    </div>
                    <button type="button" class="signature-clear-btn" id="signature-clear">Clear</button>
                </div>
                <div class="signature-stage__canvas-shell">
                    <canvas id="signature-canvas" class="signature-canvas" width="900" height="300"></canvas>
                </div>
                <div class="signature-stage__hint" id="signature-hint">Draw mode: click and drag to sign.</div>
            </div>
        </div>
        <div class="signature-modal__footer">
            <div class="signature-modal__status" id="signature-status">Create a signature, then place it on the current page.</div>
            <div class="signature-modal__actions">
                <button type="button" class="signature-btn" id="signature-cancel">Cancel</button>
                <button type="button" class="signature-btn signature-btn--primary" id="signature-apply" disabled>Use signature</button>
            </div>
        </div>
    </div>
</div>
