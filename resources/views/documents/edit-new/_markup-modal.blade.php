<div class="markup-modal" id="markup-tool-modal" aria-hidden="true">
    <div class="markup-modal__scrim" id="markup-tool-modal-scrim"></div>
    <div class="markup-modal__card" role="dialog" aria-modal="true" aria-labelledby="markup-tool-modal-title">
        <div class="markup-modal__header">
            <div>
                <p class="markup-modal__eyebrow">Markup Tool</p>
                <h2 class="markup-modal__title" id="markup-tool-modal-title">Draw or erase annotations</h2>
                <p class="markup-modal__subtitle">Sketch a freehand mark and place it on the PDF, or switch to erase mode to remove any annotation directly from the page.</p>
            </div>
            <button type="button" class="markup-modal__close" id="markup-tool-modal-close" aria-label="Close draw and erase modal">×</button>
        </div>
        <div class="markup-modal__tabs">
            <button type="button" class="markup-modal__tab is-active" data-markup-tool-mode="draw">Draw</button>
            <button type="button" class="markup-modal__tab" data-markup-tool-mode="erase">Erase</button>
        </div>
        <div class="markup-modal__body">
            <div class="markup-modal__panel is-active" data-markup-tool-panel="draw">
                <div class="markup-modal__controls">
                    <div>
                        <h3 class="markup-modal__section-title">Freehand mark</h3>
                        <p class="markup-modal__section-copy">Use your mouse, trackpad, or stylus to sketch a mark, underline, or handwritten note before placing it on the page.</p>
                    </div>
                    <div class="markup-modal__field">
                        <span class="markup-modal__field-label">Ink color</span>
                        <label class="markup-modal__color-chip">
                            <input id="markup-tool-color" type="color" value="#0f172a" aria-label="Drawing ink color">
                            <span class="markup-modal__color-value" id="markup-tool-color-value">#0F172A</span>
                        </label>
                    </div>
                    <div class="markup-modal__field">
                        <span class="markup-modal__field-label">Stroke width</span>
                        <div class="markup-modal__field-row">
                            <input id="markup-tool-width" type="range" min="1" max="10" step="1" value="4" aria-label="Drawing stroke width">
                            <span class="markup-modal__slider-value" id="markup-tool-width-value">4px</span>
                        </div>
                    </div>
                    <div class="markup-modal__field">
                        <span class="markup-modal__field-label">Stroke smoothing</span>
                        <div class="markup-modal__field-row">
                            <input id="markup-tool-smoothing" type="range" min="0" max="100" step="1" value="58" aria-label="Drawing stroke smoothing">
                            <span class="markup-modal__slider-value" id="markup-tool-smoothing-value">58%</span>
                        </div>
                    </div>
                </div>
                <div class="markup-modal__stage">
                    <div>
                        <h3 class="markup-modal__section-title">Preview</h3>
                        <p class="markup-modal__section-copy">Draw here, then place the finished mark anywhere on the current page.</p>
                    </div>
                    <div class="markup-modal__canvas-shell">
                        <canvas id="markup-tool-canvas" class="markup-modal__canvas" width="880" height="360" aria-label="Freehand drawing canvas"></canvas>
                    </div>
                    <div class="markup-modal__hint" id="markup-tool-hint">Draw mode: click and drag to create a mark.</div>
                </div>
            </div>
            <div class="markup-modal__panel" data-markup-tool-panel="erase">
                <div class="markup-modal__erase">
                    <div class="markup-modal__erase-card">
                        <strong>Erase any annotation</strong>
                        <span>After you start erase mode, click any text box, shape, signature, or image annotation on the page to remove it immediately.</span>
                    </div>
                    <div class="markup-modal__erase-card">
                        <strong>Stay in control</strong>
                        <span>Erase mode stays active until you press Escape or reopen this tool, so you can clean up multiple annotations in one pass.</span>
                    </div>
                    <div class="markup-modal__erase-card">
                        <strong>Visual targeting</strong>
                        <span>Hover over the page to preview which annotation will be deleted before you click.</span>
                    </div>
                    <div class="markup-modal__erase-card">
                        <strong>Undo still works</strong>
                        <span>Every removal uses the normal annotation delete pipeline, so undo and save behavior remain consistent.</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="markup-modal__footer">
            <div class="markup-modal__status" id="markup-tool-status">Draw a mark, then place it on the page.</div>
            <div class="markup-modal__actions">
                <button type="button" class="markup-btn" id="markup-tool-clear">Clear</button>
                <button type="button" class="markup-btn" id="markup-tool-cancel">Cancel</button>
                <button type="button" class="markup-btn markup-btn--primary" id="markup-tool-apply" disabled>Place drawing</button>
            </div>
        </div>
    </div>
</div>
