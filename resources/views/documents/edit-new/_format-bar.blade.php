<!-- Text Format Bar — shown when an annotation is selected -->
<div class="ann-format-bar" id="ann-format-bar">
    <div class="afb-sidebar-header">
        <div>
            <h2>Text Options</h2>
            <p>Selected text layer</p>
        </div>
        <button type="button" class="afb-sidebar-close" id="afb-close" aria-label="Close text options">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M18 6 6 18"></path>
                <path d="m6 6 12 12"></path>
            </svg>
        </button>
    </div>
    <div class="afb-control-group afb-font-group">
        <label class="afb-control-label" for="afb-font">Font</label>
        <!-- Font Family -->
        <select class="afb-font-select" id="afb-font" title="Font Family">
            <option value="Helvetica">Helvetica</option>
            <option value="Arial">Arial</option>
            <option value="Georgia">Georgia</option>
            <option value="TimesRoman">Times Roman</option>
            <option value="Courier">Courier</option>
            <option value="Verdana">Verdana</option>
            <option value="Palatino">Palatino</option>
            <option value="Garamond">Garamond</option>
            <option value="TrebuchetMS">Trebuchet MS</option>
            <option disabled>───── Google Fonts ─────</option>
            <option value="Roboto" style="font-family: 'Roboto', sans-serif;">Roboto</option>
            <option value="OpenSans" style="font-family: 'Open Sans', sans-serif;">Open Sans</option>
            <option value="Lato" style="font-family: 'Lato', sans-serif;">Lato</option>
            <option value="Montserrat" style="font-family: 'Montserrat', sans-serif;">Montserrat</option>
            <option value="Poppins" style="font-family: 'Poppins', sans-serif;">Poppins</option>
            <option value="SourceSansPro" style="font-family: 'Source Sans 3', sans-serif;">Source Sans 3</option>
            <option value="Inter" style="font-family: 'Inter', sans-serif;">Inter</option>
            <option value="Nunito" style="font-family: 'Nunito', sans-serif;">Nunito</option>
            <option value="Raleway" style="font-family: 'Raleway', sans-serif;">Raleway</option>
            <option value="WorkSans" style="font-family: 'Work Sans', sans-serif;">Work Sans</option>
            <option value="NotoSans" style="font-family: 'Noto Sans', sans-serif;">Noto Sans</option>
            <option value="NotoSerif" style="font-family: 'Noto Serif', serif;">Noto Serif</option>
            <option value="Merriweather" style="font-family: 'Merriweather', serif;">Merriweather</option>
            <option value="PlayfairDisplay" style="font-family: 'Playfair Display', serif;">Playfair Display</option>
            <option value="Oswald" style="font-family: 'Oswald', sans-serif;">Oswald</option>
            <option value="RobotoSlab" style="font-family: 'Roboto Slab', serif;">Roboto Slab</option>
            <option value="RobotoMono" style="font-family: 'Roboto Mono', monospace;">Roboto Mono</option>
            <option value="RobotoCondensed" style="font-family: 'Roboto Condensed', sans-serif;">Roboto Condensed</option>
            <option value="Ubuntu" style="font-family: 'Ubuntu', sans-serif;">Ubuntu</option>
            <option value="Rubik" style="font-family: 'Rubik', sans-serif;">Rubik</option>
            <option value="DMSans" style="font-family: 'DM Sans', sans-serif;">DM Sans</option>
            <option value="Mulish" style="font-family: 'Mulish', sans-serif;">Mulish</option>
            <option value="Quicksand" style="font-family: 'Quicksand', sans-serif;">Quicksand</option>
            <option value="Kanit" style="font-family: 'Kanit', sans-serif;">Kanit</option>
            <option value="FiraSans" style="font-family: 'Fira Sans', sans-serif;">Fira Sans</option>
            <option value="Lora" style="font-family: 'Lora', serif;">Lora</option>
            <option value="Cabin" style="font-family: 'Cabin', sans-serif;">Cabin</option>
            <option value="Heebo" style="font-family: 'Heebo', sans-serif;">Heebo</option>
            <option value="Karla" style="font-family: 'Karla', sans-serif;">Karla</option>
            <option value="Manrope" style="font-family: 'Manrope', sans-serif;">Manrope</option>
            <option value="JosefinSans" style="font-family: 'Josefin Sans', sans-serif;">Josefin Sans</option>
            <option value="Dosis" style="font-family: 'Dosis', sans-serif;">Dosis</option>
            <option value="Barlow" style="font-family: 'Barlow', sans-serif;">Barlow</option>
            <option value="BebasNeue" style="font-family: 'Bebas Neue', sans-serif;">Bebas Neue</option>
            <option value="PTSans" style="font-family: 'PT Sans', sans-serif;">PT Sans</option>
            <option value="CrimsonText" style="font-family: 'Crimson Text', serif;">Crimson Text</option>
            <option value="Hind" style="font-family: 'Hind', sans-serif;">Hind</option>
            <option value="Mukta" style="font-family: 'Mukta', sans-serif;">Mukta</option>
        </select>
    </div>
    <div class="afb-divider"></div>
    <div class="afb-control-group afb-size-section">
        <label class="afb-control-label" for="afb-size">Size</label>
        <!-- Font Size -->
        <div class="afb-size-group" title="Font Size">
            <input type="range" class="afb-size-slider" id="afb-size" min="0" max="100" step="1" value="50" />
            <span class="afb-size-value" id="afb-size-value">12pt</span>
        </div>
    </div>
    <div class="afb-divider"></div>
    <div class="afb-control-group afb-color-group">
        <span class="afb-control-label">Colors</span>
        <div class="afb-control-row">
            <!-- Text Color -->
            <div class="afb-color-wrap" title="Text Color">
                <input type="color" id="afb-text-color" value="#000000" />
            </div>
            <!-- Background Color -->
            <div class="afb-color-wrap" title="Background Color">
                <input type="color" id="afb-bg-color" value="#ffffff" />
            </div>
        </div>
    </div>
    <div class="afb-divider"></div>
    <div class="afb-control-group">
        <label class="afb-control-label" for="afb-opacity">Opacity</label>
        <!-- Opacity -->
        <div class="afb-opacity-group">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#9ca3af;flex-shrink:0;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <select id="afb-opacity" title="Opacity" style="width:64px;">
                <option value="1">100%</option>
                <option value="0.9">90%</option>
                <option value="0.8">80%</option>
                <option value="0.7">70%</option>
                <option value="0.6">60%</option>
                <option value="0.5">50%</option>
                <option value="0.4">40%</option>
                <option value="0.3">30%</option>
                <option value="0.2">20%</option>
                <option value="0.1">10%</option>
            </select>
        </div>
    </div>
    <div class="afb-divider"></div>
    <div class="afb-control-group afb-style-group">
        <span class="afb-control-label">Style</span>
        <div class="afb-control-row">
            <!-- Bold -->
            <button type="button" class="afb-btn" id="afb-bold" title="Bold" aria-pressed="false"><strong>B</strong></button>
            <!-- Italic -->
            <button type="button" class="afb-btn" id="afb-italic" title="Italic" aria-pressed="false"><em>I</em></button>
            <!-- Underline -->
            <button type="button" class="afb-btn" id="afb-underline" title="Underline" aria-pressed="false" style="text-decoration:underline;">U</button>
        </div>
    </div>
    <div class="afb-divider"></div>
    <div class="afb-control-group afb-align-group">
        <span class="afb-control-label">Alignment</span>
        <div class="afb-control-row">
            <!-- Text Align -->
            <select id="afb-align" title="Text Alignment" style="width:76px;">
                <option value="left">Left</option>
                <option value="center">Center</option>
                <option value="right">Right</option>
            </select>
            <!-- Vertical Align -->
            <select id="afb-valign" title="Vertical Alignment" style="width:76px;">
                <option value="top">Top</option>
                <option value="middle">Middle</option>
                <option value="bottom">Bottom</option>
            </select>
        </div>
    </div>
    <div class="afb-divider"></div>
    @if(request()->boolean('pdfjs'))
    <div class="afb-control-group afb-text-tools-group">
        <span class="afb-control-label">Text tools</span>
        <div class="afb-control-row">
            <button type="button" class="afb-btn" id="afb-uppercase" title="Uppercase" aria-label="Uppercase">Tt</button>
            <button type="button" class="afb-btn" id="afb-lowercase" title="Lowercase" aria-label="Lowercase">tl</button>
            <button type="button" class="afb-btn afb-debug" id="afb-debug" title="Debug mask" aria-label="Debug mask">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 2v4"></path><path d="M16 2v4"></path><path d="M9 9h6"></path><path d="M9 13h6"></path><path d="M12 17h.01"></path><rect x="5" y="6" width="14" height="15" rx="2"></rect></svg>
            </button>
        </div>
    </div>
    <div class="afb-divider"></div>
    @endif
    <div class="afb-control-group afb-action-group">
        <span class="afb-control-label">Actions</span>
        <div class="afb-control-row">
            <!-- Duplicate -->
            <button type="button" class="afb-btn" id="afb-copy" title="Duplicate annotation">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
            </button>
            <!-- Delete -->
            <button type="button" class="afb-btn is-danger" id="afb-delete" title="Delete annotation">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            </button>
        </div>
    </div>
</div>
