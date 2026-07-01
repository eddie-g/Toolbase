<div class="shape-tool-panel" id="shape-tool-panel">
    <div class="sfb-sidebar-header">
        <div>
            <h2>Shape Options</h2>
            <p>Selected shape layer</p>
        </div>
        <button type="button" class="sfb-sidebar-close" id="shape-tool-close" aria-label="Close shape options">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M18 6 6 18"></path>
                <path d="m6 6 12 12"></path>
            </svg>
        </button>
    </div>
    <div class="sfb-control-group sfb-type-group">
        <span class="sfb-control-label">Shape</span>
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
            <button type="button" class="sfb-shape-btn" data-shape-tool="line" title="Line" aria-label="Line">
                <svg viewBox="0 0 24 24"><path d="M5 19 19 5"></path></svg>
            </button>
            <button type="button" class="sfb-shape-btn sfb-more-shapes-toggle" id="shape-more-toggle" title="More shapes" aria-label="More shapes" aria-expanded="false" aria-controls="shape-more-grid">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6l-.04.04a2 2 0 1 1-3.92 0L10 20a1.7 1.7 0 0 0-1-.6 1.7 1.7 0 0 0-1.88.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1l-.04-.04a2 2 0 1 1 0-3.92L4 10a1.7 1.7 0 0 0 .6-1 1.7 1.7 0 0 0-.34-1.88l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6l.04-.04a2 2 0 1 1 3.92 0L14 4a1.7 1.7 0 0 0 1 .6 1.7 1.7 0 0 0 1.88-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9a1.7 1.7 0 0 0 .6 1l.04.04a2 2 0 1 1 0 3.92L20 14a1.7 1.7 0 0 0-.6 1z"></path></svg>
            </button>
        </div>
        <div class="sfb-shape-grid sfb-extra-shapes" id="shape-more-grid">
            <button type="button" class="sfb-shape-btn" data-shape-tool="star" title="Star" aria-label="Star">
                <svg viewBox="0 0 24 24"><path d="m12 4 2.35 4.76 5.25.76-3.8 3.7.9 5.23L12 16l-4.7 2.45.9-5.23-3.8-3.7 5.25-.76z"></path></svg>
            </button>
            <button type="button" class="sfb-shape-btn" data-shape-tool="x" title="X mark" aria-label="X mark">
                <svg viewBox="0 0 24 24"><path d="M6 6 18 18M18 6 6 18"></path></svg>
            </button>
            <button type="button" class="sfb-shape-btn" data-shape-tool="heart" title="Heart" aria-label="Heart">
                <svg viewBox="0 0 24 24"><path d="M12 20s-7-4.4-9.2-8.4C.7 7.8 3 4 6.7 4 8.9 4 10.4 5.2 12 7c1.6-1.8 3.1-3 5.3-3 3.7 0 6 3.8 3.9 7.6C19 15.6 12 20 12 20z"></path></svg>
            </button>
        </div>
    </div>
    <div class="afb-divider"></div>
    <div class="sfb-control-group sfb-stroke-group">
        <span class="sfb-control-label">Stroke</span>
        <label class="sfb-color" title="Stroke color" aria-label="Stroke color">
            <input type="color" id="shape-stroke-color" value="#0f172a">
        </label>
        <input type="hidden" id="shape-stroke-hex" value="#0f172a">
        <input type="checkbox" id="shape-stroke-transparent" style="display:none;">
        <div class="sfb-range-row">
            <span class="sfb-mini-label" title="Width">Width</span>
            <input type="range" class="sfb-slider" id="shape-stroke-width" min="0" max="24" step="1" value="3" title="Stroke width">
            <span class="sfb-readout" id="shape-stroke-width-value">3px</span>
        </div>
        <div class="sfb-range-row">
            <span class="sfb-mini-label" title="Opacity">Opacity</span>
            <input type="range" class="sfb-slider" id="shape-stroke-opacity" min="0" max="100" step="1" value="100" title="Stroke opacity">
            <span class="sfb-readout" id="shape-stroke-opacity-value">100%</span>
        </div>
    </div>
    <div class="afb-divider"></div>
    <div class="sfb-control-group sfb-fill-group">
        <span class="sfb-control-label">Fill</span>
        <label class="sfb-color" title="Fill color" aria-label="Fill color">
            <input type="color" id="shape-fill-color" value="#22c55e">
        </label>
        <input type="hidden" id="shape-fill-hex" value="#22c55e">
        <input type="checkbox" id="shape-fill-transparent" style="display:none;">
        <div class="sfb-range-row">
            <span class="sfb-mini-label" title="Opacity">Opacity</span>
            <input type="range" class="sfb-slider" id="shape-fill-opacity" min="0" max="100" step="1" value="22" title="Fill opacity">
            <span class="sfb-readout" id="shape-fill-opacity-value">22%</span>
        </div>
    </div>
</div>
