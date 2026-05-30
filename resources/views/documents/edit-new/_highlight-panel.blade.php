<div class="draw-tool-panel highlight-tool-panel" id="highlight-tool-panel" aria-hidden="true">
    <div class="draw-tool-panel__head">
        <p class="draw-tool-panel__title">Highlight</p>
        <button type="button" class="draw-tool-panel__close" id="highlight-tool-close" aria-label="Close highlight tool">×</button>
    </div>
    <div class="draw-tool-panel__stack">
        <div class="draw-tool-panel__slider-wrap">
            <span class="draw-tool-panel__section-label">Alpha</span>
            <input type="range" id="highlight-tool-opacity" min="15" max="80" step="1" value="35" aria-label="Highlight opacity">
            <span class="draw-tool-panel__readout" id="highlight-tool-opacity-value">35%</span>
        </div>
        <div class="draw-tool-panel__color-wrap">
            <span class="draw-tool-panel__section-label">Color</span>
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
    <div class="draw-tool-panel__status" id="highlight-tool-status">Select text to highlight it, or drag over the page.</div>
</div>
