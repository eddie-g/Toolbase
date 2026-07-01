<div class="draw-tool-panel highlight-tool-panel" id="highlight-tool-panel" aria-hidden="true">
    <div class="draw-tool-panel__head">
        <div>
            <h2 class="draw-tool-panel__title">Highlight Options</h2>
            <p class="draw-tool-panel__subtitle">Text and area markup</p>
        </div>
        <button type="button" class="draw-tool-panel__close" id="highlight-tool-close" aria-label="Close highlight tool">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M18 6 6 18"></path>
                <path d="m6 6 12 12"></path>
            </svg>
        </button>
    </div>
    <div class="draw-tool-panel__stack">
        <div class="draw-tool-panel__section">
            <label class="draw-tool-panel__section-label" for="highlight-tool-opacity">Opacity</label>
            <div class="draw-tool-panel__slider-wrap">
                <input type="range" id="highlight-tool-opacity" min="15" max="80" step="1" value="35" aria-label="Highlight opacity">
                <span class="draw-tool-panel__readout" id="highlight-tool-opacity-value">35%</span>
            </div>
        </div>
        <div class="afb-divider"></div>
        <div class="draw-tool-panel__section draw-tool-panel__color-wrap">
            <span class="draw-tool-panel__section-label">Color</span>
            <div class="draw-tool-panel__color-row">
                <div class="draw-tool-panel__colors">
                    <button type="button" class="draw-color-swatch is-active" data-highlight-color="#facc15" style="--swatch-color:#facc15" aria-label="Yellow highlight"></button>
                    <button type="button" class="draw-color-swatch" data-highlight-color="#fb923c" style="--swatch-color:#fb923c" aria-label="Orange highlight"></button>
                    <button type="button" class="draw-color-swatch" data-highlight-color="#86efac" style="--swatch-color:#86efac" aria-label="Green highlight"></button>
                    <button type="button" class="draw-color-swatch" data-highlight-color="#7dd3fc" style="--swatch-color:#7dd3fc" aria-label="Blue highlight"></button>
                    <button type="button" class="draw-color-swatch" data-highlight-color="#f9a8d4" style="--swatch-color:#f9a8d4" aria-label="Pink highlight"></button>
                    <button type="button" class="draw-color-swatch" data-highlight-color="#c4b5fd" style="--swatch-color:#c4b5fd" aria-label="Violet highlight"></button>
                </div>
                <input type="color" id="highlight-tool-color" class="draw-tool-panel__custom-color" value="#facc15" aria-label="Custom highlight color">
            </div>
        </div>
    </div>
    <div class="draw-tool-panel__status" id="highlight-tool-status">Select text to highlight it, or drag over the page.</div>
</div>
