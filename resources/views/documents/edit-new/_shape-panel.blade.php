<div class="shape-tool-panel" id="shape-tool-panel">
    <div class="sfb-shape-grid" id="shape-type-grid">
        <button type="button" class="sfb-shape-btn is-active" data-shape-tool="circle" title="Circle" aria-label="Circle">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"></circle></svg>
        </button>
        <button type="button" class="sfb-shape-btn" data-shape-tool="triangle" title="Triangle" aria-label="Triangle">
            <svg viewBox="0 0 24 24"><path d="M12 5 19 18H5z"></path></svg>
        </button>
        <button type="button" class="sfb-shape-btn" data-shape-tool="square" title="Square" aria-label="Square">
            <svg viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12"></rect></svg>
        </button>
        <button type="button" class="sfb-shape-btn" data-shape-tool="star" title="Star" aria-label="Star">
            <svg viewBox="0 0 24 24"><path d="m12 4 2.35 4.76 5.25.76-3.8 3.7.9 5.23L12 16l-4.7 2.45.9-5.23-3.8-3.7 5.25-.76z"></path></svg>
        </button>
        <button type="button" class="sfb-shape-btn" data-shape-tool="line" title="Line" aria-label="Line">
            <svg viewBox="0 0 24 24"><path d="M5 19 19 5"></path></svg>
        </button>
    </div>
    <div class="afb-divider"></div>
    <span class="sfb-label">Stroke</span>
    <label class="sfb-color" title="Stroke color" aria-label="Stroke color">
        <input type="color" id="shape-stroke-color" value="#0f172a">
    </label>
    <input type="hidden" id="shape-stroke-hex" value="#0f172a">
    <input type="checkbox" id="shape-stroke-transparent" style="display:none;">
    <span class="sfb-mini-label" title="Width">W</span>
    <input type="range" class="sfb-slider" id="shape-stroke-width" min="1" max="24" step="1" value="3" title="Stroke width">
    <span class="sfb-readout" id="shape-stroke-width-value">3px</span>
    <span class="sfb-mini-label" title="Opacity">α</span>
    <input type="range" class="sfb-slider" id="shape-stroke-opacity" min="0" max="100" step="1" value="100" title="Stroke opacity">
    <span class="sfb-readout" id="shape-stroke-opacity-value">100%</span>
    <div class="afb-divider"></div>
    <span class="sfb-label">Fill</span>
    <label class="sfb-color" title="Fill color" aria-label="Fill color">
        <input type="color" id="shape-fill-color" value="#22c55e">
    </label>
    <input type="hidden" id="shape-fill-hex" value="#22c55e">
    <input type="checkbox" id="shape-fill-transparent" style="display:none;">
    <span class="sfb-mini-label" title="Opacity">α</span>
    <input type="range" class="sfb-slider" id="shape-fill-opacity" min="0" max="100" step="1" value="22" title="Fill opacity">
    <span class="sfb-readout" id="shape-fill-opacity-value">22%</span>
</div>
