<div class="draw-tool-panel" id="draw-tool-panel" aria-hidden="true">
    <div class="draw-tool-panel__head">
        <div>
            <h2 class="draw-tool-panel__title">Draw Options</h2>
            <p class="draw-tool-panel__subtitle">Freehand markup</p>
        </div>
        <button type="button" class="draw-tool-panel__close" id="draw-tool-close" aria-label="Close draw tool">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M18 6 6 18"></path>
                <path d="m6 6 12 12"></path>
            </svg>
        </button>
    </div>
    <div class="draw-tool-panel__stack">
        <div class="draw-tool-panel__section">
            <span class="draw-tool-panel__section-label">Tool</span>
            <div class="draw-tool-panel__tool-group">
                <button type="button" class="draw-tool-btn is-active" id="draw-tool-pen" data-draw-direct-tool="pen" title="Pen" aria-label="Pen">
                    <svg viewBox="0 0 24 24"><path d="M4 20h8"></path><path d="M14.5 4.5a2.1 2.1 0 0 1 3 3L8 17l-4 1 1-4 9.5-9.5z"></path><path d="M13 6l5 5"></path></svg>
                </button>
                <button type="button" class="draw-tool-btn" id="draw-tool-eraser" data-draw-direct-tool="eraser" title="Eraser" aria-label="Eraser">
                    <svg viewBox="0 0 24 24"><path d="m7 21-4-4 11.5-11.5a2.8 2.8 0 0 1 4 4L7 21Z"></path><path d="m11 9 4 4"></path><path d="M7 21h13"></path></svg>
                </button>
            </div>
        </div>
        <div class="afb-divider"></div>
        <div class="draw-tool-panel__section">
            <label class="draw-tool-panel__section-label" for="draw-tool-size">Size</label>
            <div class="draw-tool-panel__slider-wrap">
                <input type="range" id="draw-tool-size" min="2" max="36" step="1" value="10" aria-label="Draw brush size">
                <span class="draw-tool-panel__readout" id="draw-tool-size-value">10px</span>
            </div>
        </div>
        <div class="afb-divider"></div>
        <div class="draw-tool-panel__section">
            <label class="draw-tool-panel__section-label" for="draw-tool-opacity">Opacity</label>
            <div class="draw-tool-panel__slider-wrap">
                <input type="range" id="draw-tool-opacity" min="10" max="100" step="1" value="100" aria-label="Draw opacity">
                <span class="draw-tool-panel__readout" id="draw-tool-opacity-value">100%</span>
            </div>
        </div>
        <div class="afb-divider"></div>
        <div class="draw-tool-panel__section draw-tool-panel__color-wrap" id="draw-tool-ink-colors">
            <span class="draw-tool-panel__section-label">Color</span>
            <div class="draw-tool-panel__color-row">
                <div class="draw-tool-panel__colors">
                    <button type="button" class="draw-color-swatch is-active" data-draw-color="#111827" style="--swatch-color:#111827" aria-label="Black ink"></button>
                    <button type="button" class="draw-color-swatch" data-draw-color="#dc2626" style="--swatch-color:#dc2626" aria-label="Red ink"></button>
                    <button type="button" class="draw-color-swatch" data-draw-color="#2563eb" style="--swatch-color:#2563eb" aria-label="Blue ink"></button>
                    <button type="button" class="draw-color-swatch" data-draw-color="#16a34a" style="--swatch-color:#16a34a" aria-label="Green ink"></button>
                    <button type="button" class="draw-color-swatch" data-draw-color="#ea580c" style="--swatch-color:#ea580c" aria-label="Orange ink"></button>
                    <button type="button" class="draw-color-swatch" data-draw-color="#7c3aed" style="--swatch-color:#7c3aed" aria-label="Violet ink"></button>
                </div>
                <input type="color" id="draw-tool-color" class="draw-tool-panel__custom-color" value="#111827" aria-label="Custom ink color">
            </div>
        </div>
        <div class="draw-tool-panel__section draw-tool-panel__color-wrap" id="draw-tool-eraser-color" hidden>
            <span class="draw-tool-panel__section-label">Color</span>
            <button type="button" class="draw-color-swatch is-active" style="--swatch-color:#ffffff" aria-label="White eraser color" disabled></button>
        </div>
    </div>
    <div class="draw-tool-panel__status" id="draw-tool-status">Pen mode is active. Drag directly on the page to draw.</div>
</div>
