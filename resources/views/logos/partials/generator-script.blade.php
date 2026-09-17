{{-- The Logo Lab's Alpine component, logoGenerator(): every generator
     behaviour, shared by the contained page (logo-lab) and the sidelined
     full-screen one (logo-generator-2). Expects $logoUser and
     $logoGeneratorSettings from the including view. --}}
    <script>
        function logoGenerator() {
            return {
                // Model and configuration
                selectedModel: 'flux',
                logoCount: 2,
                logoDomain: '',
                logoPrompt: '',
                logoStyle: 'default',
                logoTheme: '',
                logoColorPalette: 'none',
                showGenerationSettings: false,
                showLeftPanel: true,
                logoMode: 'icon_only',
                styleModalTab: 'style',
                
                // Tab state
                activeTab: 'generator',
                
                // Legacy vector state retained for generated SVG processing.
                editorSvgUrl: null,
                editorSvgElement: null,
                selectedElements: [],
                selectedElementColor: '#000000',
                editorText: '',
                editorFontSize: 48,
                editorFontBold: true,
                editorFontItalic: false,
                editorTextUseVector: false,
                selectedTextContent: '',
                selectedTextFontFamily: 'Arial',
                selectedTextFontSize: 48,
                selectedTextBold: false,
                selectedTextItalic: false,
                textFontOptions: [
                    'Inter', 'Poppins', 'Montserrat', 'Roboto', 'Open Sans', 'Lato', 'Nunito', 'DM Sans', 'Work Sans', 'Source Sans 3',
                    'Playfair Display', 'Merriweather', 'Libre Baskerville', 'Cormorant Garamond', 'Cinzel', 'Bitter', 'Arvo',
                    'Oswald', 'Anton', 'Bebas Neue', 'Bungee', 'Space Grotesk', 'Exo 2', 'Fira Sans', 'IBM Plex Sans', 'Rubik',
                    'Raleway', 'Josefin Sans', 'Cabin', 'Comfortaa', 'Dancing Script', 'Lobster', 'Abril Fatface', 'Macondo',
                    'Arial', 'Helvetica', 'Times New Roman', 'Georgia', 'Verdana', 'Trebuchet MS', 'Courier New', 'Impact'
                ],
                editingTextElement: null,
                // Selection box for marquee selection
                isSelecting: false,
                selectionStartX: 0,
                selectionStartY: 0,
                selectionEndX: 0,
                selectionEndY: 0,
                selectionRectElement: null,
                hoverMenu: { visible: false, x: 0, y: 0 },
                replaceTargetElement: null,
                editorFontFamily: 'Arial',
                editorTextColor: '#000000',
                editorZoom: 1,
                canvasSize: 'default',
                svgLayers: [],
                editMode: false,
                editGroupMode: false,
                editingGroup: null,
                showImportModal: false,
                showColorModal: false,
                showTextModal: false,
                showShapeModal: false,
                showLayersModal: false,
                importModalTab: 'session',
                userLogos: [],
                loadingUserLogos: false,
                undoStack: [],
                showSaveStateModal: false,
                saveStateName: '',
                selectedStateToOverwrite: null,
                editorStates: [],
                editorShapeType: 'rectangle',
                editorShapeSize: 120,
                editorShapeFill: '#38BDF8',
                editorShapeStroke: '#0F172A',
                editorShapeStrokeWidth: 2,
                logoCustomColors: ['#1e3a5f', '#d4af37', '#333333'],
                backgroundColor: 'white',
                backgroundCustomColor: '#4F46E5',
                shapeContainer: '',
                detailLevel: 'medium',
                proMode: true,
                proSize: '512',
                workMode: 'logo', // 'image' or 'logo'
                outputFormat: 'vector',
                imageFormat: 'png',
                genMode: 'logo', // 'logo' or 'image' content (image/raster workMode only)
                imageSize: '1:1', // '1:1' | '16:9' | '9:16'
                seed: null,

                // State
                logoBatches: [],
                generating: false,
                logoPrice: 0,
                upscalePrice: @js((float) \App\Models\AiLogoPrice::estimateUpscaleCost()['cost_per_image']),
                creditBalance: @js((float) ($logoUser->credit_balance ?? 0)),
                error: null,
                showStyleModal: false,
                zoomImageUrl: null,
                similarIdeas: [],

                // Palette management
                canManagePalettes: @js((bool) $logoUser),
                savedPalettes: [],
                savedPaletteName: '',
                paletteSaving: false,
                paletteLoading: false,
                paletteError: null,
                paletteSuccess: null,

                // Persistent generator settings
                canSaveSettings: @js((bool) $logoUser),
                savedLogoSettings: @js($logoGeneratorSettings ?? []),
                settingsSaving: false,
                settingsStatus: null,
                settingsError: null,

                // Available options
                colorPalettes: [
                    { id: 'fire',   name: 'Fire',   colors: ['#D00000', '#E85D04', '#FFBA08'] },
                    { id: 'pastel', name: 'Pastel', colors: ['#FFB5A7', '#FCD5CE', '#A2D2FF'] },
                    { id: 'royal',  name: 'Royal',  colors: ['#7B2CBF', '#C77DFF', '#E0AAFF'] },
                    { id: 'ice',    name: 'Ice',    colors: ['#0077B6', '#00B4D8', '#90E0EF'] },
                ],

                normalizeHexColor(value, fallback = '#ffffff') {
                    const v = String(value || '').trim();
                    return /^#[0-9a-fA-F]{6}$/.test(v) ? v : fallback;
                },

                normalizePaletteColors(colors) {
                    if (!Array.isArray(colors)) return [];
                    return colors
                        .map((color) => this.normalizeHexColor(color, ''))
                        .filter((color) => /^#[0-9a-fA-F]{6}$/.test(color))
                        .map((color) => color.toUpperCase())
                        .slice(0, 5);
                },

                currentLogoGeneratorSettings() {
                    return {
                        selected_model: this.selectedModel,
                        logo_count: Number(this.logoCount) || 2,
                        logo_domain: this.logoDomain || '',
                        logo_prompt: this.logoPrompt || '',
                        logo_style: this.logoStyle || 'default',
                        logo_theme: this.logoTheme || '',
                        logo_color_palette: this.logoColorPalette || 'none',
                        logo_custom_colors: this.normalizePaletteColors(this.logoCustomColors),
                        background_color: this.backgroundColor || 'white',
                        background_custom_color: this.normalizeHexColor(this.backgroundCustomColor, '#4F46E5'),
                        logo_mode: this.logoMode || 'icon_only',
                        pro_mode: Boolean(this.proMode),
                        pro_size: parseInt(this.proSize || '512', 10),
                        detail_level: this.detailLevel || 'medium',
                        shape_container: this.shapeContainer || '',
                        work_mode: this.workMode || 'logo',
                        output_format: this.outputFormat || 'vector',
                        image_format: this.imageFormat || 'png',
                        gen_mode: this.genMode || 'logo',
                        image_size: this.imageSize || '1:1',
                    };
                },

                applyLogoGeneratorSettings(settings, options = {}) {
                    if (!settings || typeof settings !== 'object' || Object.keys(settings).length === 0) {
                        return;
                    }

                    this.selectedModel = ['flux', 'recraft', 'dalle'].includes(settings.selected_model) ? settings.selected_model : this.selectedModel;
                    this.logoCount = Math.max(1, Math.min(4, parseInt(settings.logo_count || this.logoCount, 10) || this.logoCount));
                    this.logoDomain = String(settings.logo_domain || '');
                    this.logoPrompt = String(settings.logo_prompt || '');
                    this.logoStyle = String(settings.logo_style || 'default');
                    this.logoTheme = ['real_estate', 'nature', 'fantasy', 'technology'].includes(settings.logo_theme) ? settings.logo_theme : '';
                    this.logoColorPalette = String(settings.logo_color_palette || 'none');

                    const colors = this.normalizePaletteColors(settings.logo_custom_colors || []);
                    if (colors.length >= 2) {
                        this.logoCustomColors = colors;
                    }

                    const background = String(settings.background_color || 'white');
                    this.backgroundColor = background;
                    this.backgroundCustomColor = this.normalizeHexColor(settings.background_custom_color || (background.startsWith('#') ? background : '#4F46E5'), '#4F46E5');
                    this.logoMode = ['icon_only', 'icon_text', 'text_only'].includes(settings.logo_mode) ? settings.logo_mode : 'icon_only';
                    this.proMode = Boolean(settings.pro_mode);

                    const proSize = parseInt(settings.pro_size, 10);
                    this.proSize = String([512, 1024, 1536].includes(proSize) ? proSize : 512);
                    this.detailLevel = ['min', 'medium', 'max'].includes(settings.detail_level) ? settings.detail_level : 'medium';
                    this.shapeContainer = ['circle', 'square', 'hexagon', 'triangle', 'pentagon'].includes(settings.shape_container) ? settings.shape_container : '';
                    this.workMode = ['logo', 'image'].includes(settings.work_mode) ? settings.work_mode : 'logo';
                    this.outputFormat = ['raster', 'vector'].includes(settings.output_format) ? settings.output_format : 'vector';
                    this.imageFormat = ['png', 'bmp'].includes(settings.image_format) ? settings.image_format : 'png';
                    this.genMode = ['logo', 'image'].includes(settings.gen_mode) ? settings.gen_mode : 'logo';
                    this.imageSize = ['1:1', '16:9', '9:16'].includes(settings.image_size) ? settings.image_size : '1:1';

                    if (this.workMode === 'logo') {
                        this.outputFormat = 'vector';
                        this.genMode = 'logo';
                        if (this.selectedModel === 'dalle') {
                            this.selectedModel = 'recraft';
                        }
                        if (this.logoMode === 'icon_text') {
                            this.logoMode = 'icon_only';
                        }
                    }

                    if (this.selectedModel === 'dalle' && this.imageFormat !== 'png') {
                        this.imageFormat = 'png';
                    }

                    this.enforceLunaVectorDefaults();
                    if (options.fetch !== false) {
                        this.fetchLogoPrice();
                    }
                },

                async saveLogoGeneratorSettings() {
                    if (!this.canSaveSettings || this.settingsSaving) {
                        return;
                    }

                    this.settingsSaving = true;
                    this.settingsStatus = null;
                    this.settingsError = null;

                    try {
                        const response = await fetch('/domain-search/logo-generator-settings', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({ settings: this.currentLogoGeneratorSettings() }),
                        });
                        const data = await response.json().catch(() => ({}));

                        if (!response.ok) {
                            this.settingsError = data.error || data.message || 'Failed to save settings.';
                            return;
                        }

                        this.savedLogoSettings = data.settings || this.currentLogoGeneratorSettings();
                        this.settingsStatus = 'Settings saved.';
                    } catch (e) {
                        this.settingsError = 'Network error saving settings.';
                    } finally {
                        this.settingsSaving = false;
                    }
                },

                init() {
                    this.applyLogoGeneratorSettings(this.savedLogoSettings, { fetch: false });
                    if (String(this.backgroundColor || '').startsWith('#')) {
                        this.backgroundCustomColor = this.normalizeHexColor(this.backgroundColor, '#4F46E5');
                    }
                    this.enforceLunaVectorDefaults();
                    this.ensureSupportedImageSize();

                    // Delay initial price fetch to ensure everything is loaded
                    this.$nextTick(() => {
                        this.fetchLogoPrice();
                    });
                    this.fetchSavedPalettes();
                },

                selectModel(model) {
                    this.selectedModel = model;
                    if (['skyline_swoosh', 'evergreen_silhouette'].includes(this.logoStyle)) {
                        this.logoStyle = this.outputFormat === 'vector' ? 'minimal_geometric' : 'professional';
                    }
                    // Set default pro mode based on model
                    if (model === 'dalle') {
                        this.proMode = false;
                    } else if (model === 'recraft') {
                        this.proMode = true;
                    }
                    this.enforceLunaVectorDefaults();
                    this.ensureSupportedImageSize();
                    this.fetchLogoPrice();
                },

                switchToImageMode() {
                    this.workMode = 'image';
                    this.outputFormat = 'raster';
                    this.ensureSupportedImageSize();

                    if (this.selectedModel === 'dalle' && this.imageFormat !== 'png') {
                        this.imageFormat = 'png';
                    }

                    if (this.activeTab === 'editor') {
                        this.activeTab = 'generator';
                    }

                    const vectorStyles = ['minimal_geometric', 'abstract', 'monoline', 'negative_space', 'tech_gradient', 'skyline_swoosh', 'evergreen_silhouette', 'modern_sans', 'bold_geometric', 'elegant_serif', 'script_signature', 'tech_mono', 'minimal_light'];
                    if (vectorStyles.includes(this.logoStyle)) {
                        this.logoStyle = 'professional';
                    }

                    this.fetchLogoPrice();
                },

                switchToLogoMode() {
                    this.workMode = 'logo';
                    this.outputFormat = 'vector';
                    this.genMode = 'logo';

                    if (this.logoMode === 'icon_text') {
                        this.logoMode = 'icon_only';
                    }

                    if (this.selectedModel === 'dalle') {
                        this.selectedModel = 'recraft';
                    }

                    const rasterStyles = ['professional', 'fantasy', 'future', 'retro', 'minimalist', 'greetingcard', 'photorealistic'];
                    const textStyles = ['modern_sans', 'bold_geometric', 'elegant_serif', 'script_signature', 'tech_mono', 'minimal_light'];
                    if (this.logoMode === 'text_only') {
                        if (this.logoStyle !== 'default' && !textStyles.includes(this.logoStyle)) {
                            this.logoStyle = 'modern_sans';
                        }
                    } else if (this.logoStyle !== 'default' && (rasterStyles.includes(this.logoStyle) || textStyles.includes(this.logoStyle))) {
                        this.logoStyle = 'minimal_geometric';
                    }

                    this.enforceLunaVectorDefaults();
                    this.ensureSupportedImageSize();
                    this.fetchLogoPrice();
                },

                isLunaVectorMode() {
                    return this.selectedModel === 'flux' && this.outputFormat === 'vector';
                },

                isSampledVectorMode() {
                    return this.outputFormat === 'vector' && (this.selectedModel === 'flux' || this.selectedModel === 'recraft');
                },

                getVectorSampleUrl(style) {
                    const lunaSamples = {
                        minimal_geometric: '/images/luna_vector_samples/lion_minimal_luna.png',
                        abstract: '/images/luna_vector_samples/lion_abstract_luna.png',
                        monoline: '/images/luna_vector_samples/lion_monoline_luna.png',
                        negative_space: '/images/luna_vector_samples/lion_negative_space_luna.png',
                        tech_gradient: '/images/luna_vector_samples/tech_gradient_lion_luna.png',
                    };

                    const raySamples = {
                        minimal_geometric: '/images/ray_vector_samples/ray_minimal_vector.png',
                        abstract: '/images/ray_vector_samples/ray_abstract_vector.png',
                        monoline: '/images/ray_vector_samples/ray_monoline_vector.png',
                        negative_space: '/images/ray_vector_samples/ray_negative_space_vector.png',
                        tech_gradient: '/images/ray_vector_samples/ray_tech_gradient_vector.png',
                        evergreen_silhouette: '/images/ray_vector_samples/ray_evergreen_silhouette_vector.svg',
                    };

                    const sampleMap = this.selectedModel === 'recraft' ? raySamples : lunaSamples;
                    return sampleMap[style] || '';
                },

                isTextStyle(style) {
                    return ['modern_sans', 'bold_geometric', 'elegant_serif', 'script_signature', 'tech_mono', 'minimal_light'].includes(style);
                },

                enforceLunaVectorDefaults() {
                    if (this.isLunaVectorMode()) {
                        this.proMode = true;
                        this.proSize = '512';
                    }
                },

                getEffectiveProSettings() {
                    if (this.isLunaVectorMode()) {
                        return { pro: true, proSize: 512 };
                    }

                    if (this.selectedModel === 'recraft' && this.outputFormat === 'vector') {
                        return { pro: false, proSize: 512 };
                    }

                    return {
                        pro: this.proMode,
                        proSize: this.proMode ? parseInt(this.proSize) : 1024,
                    };
                },

                imageSizeOptions() {
                    if (this.selectedModel === 'recraft' && this.outputFormat === 'raster' && this.getEffectiveProSettings().pro) {
                        return [
                            { id: '1:1', label: 'Square' },
                        ];
                    }

                    return [
                        { id: '1:1', label: 'Square' },
                        { id: '16:9', label: 'Landscape' },
                        { id: '9:16', label: 'Portrait' },
                    ];
                },

                ensureSupportedImageSize() {
                    const supported = this.imageSizeOptions().map(option => option.id);
                    if (!supported.includes(this.imageSize)) {
                        this.imageSize = '1:1';
                    }
                },

                imageSizeResolutionLabel() {
                    if (this.selectedModel === 'dalle') {
                        if (this.imageSize === '16:9') return '1536x1024';
                        if (this.imageSize === '9:16') return '1024x1536';
                        return '1024x1024';
                    }

                    if (this.selectedModel === 'recraft') {
                        const isRayPro = Boolean(this.getEffectiveProSettings().pro);
                        if (this.imageSize === '16:9') return '1344x768';
                        if (this.imageSize === '9:16') return '768x1344';
                        return '1024x1024';
                    }

                    return this.imageSize;
                },

                getSelectedPaletteColors() {
                    if (this.logoColorPalette === 'none') return null;
                    if (this.logoColorPalette === 'custom') return this.logoCustomColors;
                    const p = this.colorPalettes.find(p => p.id === this.logoColorPalette);
                    return p ? p.colors : null;
                },

                isCustomBackgroundColor() {
                    return String(this.backgroundColor || '').startsWith('#');
                },

                selectCustomBackground() {
                    this.backgroundColor = this.normalizeHexColor(this.backgroundCustomColor, '#4F46E5');
                    this.fetchLogoPrice();
                },

                openAddTextModal() {
                    this.editingTextElement = null;
                    this.editorText = '';
                    this.editorFontFamily = 'Arial';
                    this.editorFontSize = 48;
                    this.editorFontBold = true;
                    this.editorFontItalic = false;
                    this.editorTextUseVector = false;
                    this.editorTextColor = '#000000';
                    this.showTextModal = true;
                },

                openTextEditorForSelected() {
                    const textEl = this.getSelectedTextElement();
                    if (!textEl) return;

                    this.editingTextElement = textEl;
                    this.editorText = textEl.textContent || '';
                    this.editorFontFamily = textEl.getAttribute('font-family') || this.editorFontFamily || 'Arial';
                    this.editorFontSize = parseFloat(textEl.getAttribute('font-size') || '48') || 48;

                    const weightRaw = String(textEl.getAttribute('font-weight') || '').toLowerCase();
                    const weightInt = parseInt(weightRaw, 10);
                    this.editorFontBold = weightRaw === 'bold' || (!Number.isNaN(weightInt) && weightInt >= 600);

                    const styleRaw = String(textEl.getAttribute('font-style') || '').toLowerCase();
                    this.editorFontItalic = styleRaw === 'italic' || styleRaw === 'oblique';

                    this.editorTextColor = textEl.getAttribute('fill') || this.editorTextColor || '#000000';
                    this.showTextModal = true;
                },

                saveTextModal() {
                    if (this.editingTextElement) {
                        this.editorText = String(this.editorText || '').trim();
                        if (!this.editorText) return;

                        const textEl = this.editingTextElement;
                        textEl.textContent = this.editorText;
                        textEl.setAttribute('font-family', this.editorFontFamily || 'Arial');
                        textEl.setAttribute('font-size', String(Math.max(8, Math.min(300, parseFloat(this.editorFontSize) || 48))));
                        textEl.setAttribute('font-weight', this.editorFontBold ? '700' : '400');
                        textEl.setAttribute('font-style', this.editorFontItalic ? 'italic' : 'normal');
                        textEl.setAttribute('fill', this.editorTextColor || '#000000');
                        textEl.setAttribute('data-layer-name', 'Text: ' + this.editorText.substring(0, 20));

                        this.syncTextEditorFromSelection();
                        this.updateLayers();
                        this.updateHoverMenuPosition();
                        this.showTextModal = false;
                        this.editingTextElement = null;
                        return;
                    }

                    this.addTextToSvg();
                    this.showTextModal = false;
                },

                applyCustomBackgroundColor(color) {
                    const hex = this.normalizeHexColor(color, '#4F46E5');
                    this.backgroundCustomColor = hex;
                    this.backgroundColor = hex;
                    this.fetchLogoPrice();
                },

                getEditorBackgroundFill() {
                    if (this.backgroundColor === 'white') return 'white';
                    if (String(this.backgroundColor || '').startsWith('#')) {
                        return this.normalizeHexColor(this.backgroundColor, 'white');
                    }
                    return null;
                },

                async fetchSavedPalettes() {
                    if (!this.canManagePalettes) return;
                    this.paletteLoading = true;
                    this.paletteError = null;
                    try {
                        const response = await fetch('/domain-search/logo-palettes', {
                            method: 'GET',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                'Accept': 'application/json',
                            },
                        });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok) {
                            this.paletteError = data.error || 'Failed to load saved palettes.';
                            return;
                        }
                        const incoming = Array.isArray(data.palettes) ? data.palettes : [];
                        this.savedPalettes = incoming
                            .map((p) => ({
                                id: p.id,
                                name: String(p.name || '').trim(),
                                colors: this.normalizePaletteColors(p.colors),
                            }))
                            .filter((p) => p.id && p.name && p.colors.length >= 2);
                    } catch (e) {
                        this.paletteError = 'Network error loading saved palettes.';
                    } finally {
                        this.paletteLoading = false;
                    }
                },

                async saveCurrentPalette() {
                    if (!this.canManagePalettes || this.paletteSaving) return;
                    const name = String(this.savedPaletteName || '').trim();
                    if (!name) {
                        this.paletteError = 'Enter a palette name.';
                        this.paletteSuccess = null;
                        return;
                    }
                    const colors = this.normalizePaletteColors(this.logoCustomColors);
                    if (colors.length < 2) {
                        this.paletteError = 'Palette needs at least 2 colors.';
                        this.paletteSuccess = null;
                        return;
                    }

                    this.paletteSaving = true;
                    this.paletteError = null;
                    this.paletteSuccess = null;

                    try {
                        const response = await fetch('/domain-search/logo-palettes', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({ name, colors }),
                        });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok) {
                            this.paletteError = data.error || 'Failed to save palette.';
                            return;
                        }
                        await this.fetchSavedPalettes();
                        this.paletteSuccess = 'Palette saved.';
                    } catch (e) {
                        this.paletteError = 'Network error saving palette.';
                    } finally {
                        this.paletteSaving = false;
                    }
                },

                applySavedPalette(palette) {
                    const colors = this.normalizePaletteColors(palette?.colors || []);
                    if (colors.length < 2) return;
                    this.logoCustomColors = colors;
                    this.logoColorPalette = 'custom';
                    this.savedPaletteName = String(palette?.name || '').trim();
                    this.paletteError = null;
                    this.paletteSuccess = `Loaded "${this.savedPaletteName}".`;
                },

                async deleteSavedPalette(paletteId) {
                    if (!this.canManagePalettes || !paletteId) return;
                    this.paletteError = null;
                    this.paletteSuccess = null;
                    try {
                        const response = await fetch('/domain-search/logo-palettes/' + encodeURIComponent(paletteId), {
                            method: 'DELETE',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                'Accept': 'application/json',
                            },
                        });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok) {
                            this.paletteError = data.error || 'Failed to delete palette.';
                            return;
                        }
                        this.savedPalettes = this.savedPalettes.filter((p) => Number(p.id) !== Number(paletteId));
                        this.paletteSuccess = 'Palette deleted.';
                    } catch (e) {
                        this.paletteError = 'Network error deleting palette.';
                    }
                },

                getStyleLabel() {
                    const labels = { default: 'Default', chrome: 'Chrome', professional: 'Professional', fantasy: 'Fantasy', future: 'Future', retro: 'Retro', '8bit': '8-Bit', dotmatrix: 'Dot Matrix', greetingcard: 'Watercolor', photorealistic: 'Photorealistic', minimal_geometric: 'Minimal Geometric', abstract: 'Abstract', monoline: 'Monoline', negative_space: 'Negative Space', tech_gradient: 'Tech Gradient', skyline_swoosh: 'Skyline Swoosh', evergreen_silhouette: 'Evergreen Silhouette', modern_sans: 'Modern Sans', bold_geometric: 'Bold Geometric', elegant_serif: 'Elegant Serif', script_signature: 'Script Signature', tech_mono: 'Tech Mono', minimal_light: 'Minimal Light' };
                    if (labels[this.logoStyle]) return labels[this.logoStyle];
                    return 'Default';
                },

                getThemeLabel() {
                    const labels = { real_estate: 'Real Estate', nature: 'Nature', fantasy: 'Fantasy', technology: 'Technology' };
                    return labels[this.logoTheme] || 'None';
                },

                selectStyle(style) {
                    this.logoStyle = style;
                    this.showStyleModal = false;
                    this.fetchLogoPrice();
                },

                selectTheme(theme) {
                    this.logoTheme = theme;
                    this.showStyleModal = false;
                    this.fetchLogoPrice();
                },

                async fetchLogoPrice() {
                    try {
                        this.ensureSupportedImageSize();
                        const proSettings = this.getEffectiveProSettings();

                        // Ensure CSRF token is available
                        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
                        if (!csrfToken) {
                            console.warn('CSRF token not available yet, skipping price fetch');
                            return;
                        }

                        const response = await fetch('/domain-search/estimate-logo-price', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken
                            },
                            body: JSON.stringify({
                                count: this.logoCount,
                                pro: proSettings.pro,
                                pro_size: proSettings.proSize,
                                style: this.logoStyle,
                                bg_color: this.backgroundColor,
                                image_model: this.selectedModel,
                                output_format: this.outputFormat,
                                image_format: this.outputFormat === 'raster' && this.selectedModel === 'dalle' ? this.imageFormat : null,
                                gen_mode: this.workMode === 'image' && this.genMode === 'image' ? 'image' : 'logo',
                                image_size: this.workMode === 'image' && this.genMode === 'image' ? this.imageSize : null,
                                recraft_substyle: null
                            })
                        });

                        if (response.ok) {
                            const data = await response.json();
                            this.logoPrice = parseFloat(data.estimated_cost_usd) || 0;
                            if (data.credit_balance !== undefined) {
                                this.creditBalance = parseFloat(data.credit_balance);
                            }
                        } else {
                            console.error('Price estimate failed:', response.status, response.statusText);
                        }
                    } catch (err) {
                        console.error('Price estimate error:', err);
                    }
                },

                isDataImageUrl(url) {
                    return /^data:image\//i.test(String(url || ''));
                },

                isLocalLogoUrl(url) {
                    return String(url || '').startsWith('/storage/logos/');
                },

                isSvgUrl(url) {
                    return /\.svg(?:[?#]|$)/i.test(String(url || '')) || /^data:image\/svg\+xml/i.test(String(url || ''));
                },

                displayUrlForGeneratedImage(img) {
                    if (!img || typeof img !== 'object') return String(img || '');
                    const storedUrl = img.stored_url || '';
                    const rawUrl = img.url || '';
                    const svgUrl = img.svg_url || '';

                    if (storedUrl) return storedUrl;
                    if (this.isDataImageUrl(rawUrl)) return rawUrl;
                    if (rawUrl && (!this.isSvgUrl(rawUrl) || this.isLocalLogoUrl(rawUrl))) return rawUrl;
                    if (svgUrl && this.isLocalLogoUrl(svgUrl)) return svgUrl;

                    return rawUrl || svgUrl;
                },

                editUrlForGeneratedImage(img) {
                    if (!img || typeof img !== 'object') return String(img || '');
                    if (img.stored_url && this.isSvgUrl(img.stored_url)) return img.stored_url;
                    return img.svg_url || img.stored_url || img.url || '';
                },

                updateGeneratedImage(batchIndex, imageIndex, updates) {
                    const batch = this.logoBatches[batchIndex];
                    if (!batch || !batch.images || !batch.images[imageIndex]) return;

                    const images = batch.images.map((image, idx) => idx === imageIndex ? { ...image, ...updates } : image);
                    this.logoBatches[batchIndex] = { ...batch, images };
                },

                async upscaleGeneratedImage(batchIndex, imageIndex) {
                    const batch = this.logoBatches[batchIndex];
                    const image = batch?.images?.[imageIndex];
                    if (!image || image.failed || image.isVector || image.upscaling) return;

                    const sourceUrl = image.displayUrl || image.editUrl || image.url;
                    if (!sourceUrl) {
                        this.updateGeneratedImage(batchIndex, imageIndex, { upscaleError: 'No image URL is available to upscale.' });
                        return;
                    }

                    this.error = null;
                    this.updateGeneratedImage(batchIndex, imageIndex, { upscaling: true, upscaleError: null });
                    const abortController = new AbortController();
                    const timeoutHandle = window.setTimeout(() => abortController.abort(), 180000);

                    try {
                        const response = await fetch('/domain-search/upscale-logo', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({
                                image_url: sourceUrl,
                                upscale_factor: 2,
                                logo_request_id: image.logoRequestId || null,
                                image_index: Number.isInteger(image.imageIndex) ? image.imageIndex : imageIndex,
                            }),
                            signal: abortController.signal,
                        });

                        const data = await response.json().catch(() => ({ error: 'Server returned invalid response.' }));
                        if (!response.ok) {
                            const message = data.error || 'Upsize failed.';
                            this.error = message;
                            if (data.credit_balance !== undefined) {
                                this.creditBalance = parseFloat(data.credit_balance);
                            }
                            this.updateGeneratedImage(batchIndex, imageIndex, { upscaling: false, upscaleError: message });
                            return;
                        }

                        const resolution = data.width && data.height ? `${data.width}x${data.height}` : '2x upscale';
                        this.updateGeneratedImage(batchIndex, imageIndex, {
                            url: data.upscaled_url,
                            displayUrl: data.upscaled_url,
                            editUrl: data.upscaled_url,
                            upscaledUrl: data.upscaled_url,
                            originalUrl: sourceUrl,
                            upscaling: false,
                            upscaleError: null,
                            metadata: {
                                ...(image.metadata || batch.metadata || {}),
                                resolution,
                                upscaled: true,
                            },
                        });

                        if (data.credit_balance !== undefined) {
                            this.creditBalance = parseFloat(data.credit_balance);
                        }
                    } catch (err) {
                        const message = err.name === 'AbortError'
                            ? 'Upsize is taking longer than expected. Please try again in a moment.'
                            : (err.message || 'Upsize failed.');
                        this.error = message;
                        this.updateGeneratedImage(batchIndex, imageIndex, { upscaling: false, upscaleError: message });
                    } finally {
                        window.clearTimeout(timeoutHandle);
                        this.updateGeneratedImage(batchIndex, imageIndex, { upscaling: false });
                    }
                },

                async generateLogo() {
                    this.ensureSupportedImageSize();
                    const proSettings = this.getEffectiveProSettings();

                    // Validate based on mode
                    const needsText = this.logoMode === 'icon_text' || this.logoMode === 'text_only';
                    const needsPrompt = this.logoMode === 'icon_only' || this.logoMode === 'icon_text';

                    if (this.workMode === 'logo' && this.logoMode === 'icon_text') {
                        this.error = 'Vector generation supports either logo or text, not both.';
                        return;
                    }
                    
                    if (needsText && !this.logoDomain) {
                        this.error = 'Please enter logo text';
                        return;
                    }
                    if (needsPrompt && !this.logoPrompt) {
                        // Prompt is optional, but at least something is needed
                        if (!this.logoDomain) {
                            this.error = 'Please enter logo text or a custom prompt';
                            return;
                        }
                    }
                    
	                    this.error = null;

	                    const estimatedTotalCost = Number(this.logoPrice || 0);
	                    const availableCreditBalance = Number(this.creditBalance || 0);
	                    if (estimatedTotalCost > 0 && availableCreditBalance < estimatedTotalCost) {
	                        this.error = 'Insufficient balance. Please add credits before generating logos.';
	                        return;
	                    }

	                    this.generating = true;
                    
                    // Create a new batch at the beginning with loading placeholders
                    const batchId = Date.now();
                    const expectedCount = this.logoCount;
                    
                    // Store metadata for this generation
                    const isRayVector = this.selectedModel === 'recraft' && this.outputFormat === 'vector';
                    const isImageContentBatch = this.workMode === 'image' && this.genMode === 'image';
                    const generationMetadata = {
                        model: this.selectedModel === 'flux' ? 'Luna' : (this.selectedModel === 'recraft' ? 'Ray' : 'Cosmo'),
                        modelId: this.selectedModel,
                        resolution: isImageContentBatch ? this.imageSizeResolutionLabel() : (isRayVector ? 'SVG 1:1' : (proSettings.pro && this.selectedModel === 'flux' ? `${proSettings.proSize}x${proSettings.proSize}` : (this.selectedModel === 'dalle' ? '1024x1024' : '512x512'))),
                        price: (this.logoPrice / this.logoCount).toFixed(4),
                        style: this.logoTheme ? this.getThemeLabel() : this.logoStyle
                    };
                    
                    this.logoBatches.unshift({
                        id: batchId,
                        timestamp: new Date().toISOString(),
                        images: [],
                        metadata: generationMetadata,
                        loading: true,
                        expectedCount: expectedCount
                    });

                    try {
                        // Step 1: Queue one generation job for the full requested count.
                        const totalCount = this.logoCount;
                        const pendingJobs = [];
                        const isImageContent = this.workMode === 'image' && this.genMode === 'image';
                        const payload = {
                            domain: (this.logoMode === 'icon_text' || this.logoMode === 'text_only') ? this.logoDomain : null,
                            custom_prompt: this.logoPrompt || '',
                            style: this.logoStyle,
                            logo_theme: this.logoTheme || null,
                            count: totalCount,
                            total_count: totalCount,
                            batch_index: 0,
                            pro: proSettings.pro,
                            pro_size: proSettings.proSize,
                            icon_only: isImageContent ? false : this.logoMode === 'icon_only',
                            text_only: isImageContent ? false : this.logoMode === 'text_only',
                            bg_color: this.backgroundColor,
                            image_model: this.selectedModel,
                            output_format: this.outputFormat,
                            image_format: this.outputFormat === 'raster' && this.selectedModel === 'dalle' ? this.imageFormat : null,
                            color_palette: this.logoColorPalette !== 'none' ? this.getSelectedPaletteColors() : null,
                            logo_shape: this.outputFormat === 'vector' ? 'none' : (this.shapeContainer || 'none'),
                            logo_detail: this.outputFormat === 'vector' ? 'max' : (this.detailLevel || 'medium'),
                            gen_mode: isImageContent ? 'image' : 'logo',
                            image_size: isImageContent ? this.imageSize : null
                        };

                        const response = await fetch('/domain-search/generate-logo', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            },
                            body: JSON.stringify(payload)
                        });

                        if (!response.ok) {
                            const data = await response.json().catch(() => ({ error: 'Server error' }));
                            this.error = data.error || 'Failed to queue logo generation';
                            if (data.credit_balance !== undefined) {
                                this.creditBalance = parseFloat(data.credit_balance);
                            }
                            this.logoBatches = this.logoBatches.filter(batch => batch.id !== batchId);
                            this.generating = false;
                            return;
                        }

                        const data = await response.json().catch(() => {
                            console.error('Failed to parse generation response as JSON');
                            return null;
                        });

                        if (!data || !data.logo_request_id) {
                            this.error = 'Server returned invalid response';
                            this.logoBatches = this.logoBatches.filter(batch => batch.id !== batchId);
                            this.generating = false;
                            return;
                        }

                        pendingJobs.push(data.logo_request_id);

                        if (data.credit_balance !== undefined) {
                            this.creditBalance = parseFloat(data.credit_balance);
                        }

                        // Step 2: Poll for completion
                        const completedJobs = new Set();
                        const failedJobs = new Set();
                        const maxPollTime = 6 * 60 * 1000; // backend job timeout plus a little room
                        const pollStart = Date.now();
                        const pollInterval = 3000; // 3 seconds

                        while (completedJobs.size + failedJobs.size < pendingJobs.length) {
                            if (Date.now() - pollStart > maxPollTime) {
                                this.error = 'Logo generation is still processing. Refresh in a moment to check the latest status.';
                                break;
                            }

                            await new Promise(resolve => setTimeout(resolve, pollInterval));

                            for (const jobId of pendingJobs) {
                                if (completedJobs.has(jobId) || failedJobs.has(jobId)) continue;

                                try {
                                    const statusRes = await fetch('/domain-search/logo-status/' + jobId, {
                                        headers: {
                                            'Content-Type': 'application/json',
                                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                                        }
                                    });

                                    const statusData = await statusRes.json().catch(err => {
                                        console.error('Failed to parse status response as JSON for job', jobId, err);
                                        return { status: 'failed', error: 'Invalid server response' };
                                    });

                                    if (statusData.status === 'completed') {
                                        completedJobs.add(jobId);

                                        const batchIdx = this.logoBatches.findIndex(b => b.id === batchId);
                                        const existingBatch = batchIdx !== -1 ? this.logoBatches[batchIdx] : null;
                                        const newImages = (statusData.images || []).map((img, resultIndex) => {
                                            const displayUrl = this.displayUrlForGeneratedImage(img);
                                            const editUrl = this.editUrlForGeneratedImage(img);
                                            return {
                                                key: img.generation_id || `${jobId}-${resultIndex}`,
                                                url: displayUrl,
                                                displayUrl,
                                                editUrl,
                                                logoRequestId: jobId,
                                                imageIndex: resultIndex,
                                                generationId: img.generation_id || null,
                                                providerImageId: img.provider_image_id || null,
                                                seed: statusData.seed || null,
                                                isVector: this.outputFormat === 'vector',
                                                metadata: existingBatch?.metadata || {}
                                            };
                                        });

                                        // Replace batch object to ensure Alpine detects the nested array change
                                        if (batchIdx !== -1) {
                                            this.logoBatches[batchIdx] = {
                                                ...this.logoBatches[batchIdx],
                                                images: [...this.logoBatches[batchIdx].images, ...newImages]
                                            };
                                        }

                                        if (statusData.credit_balance !== undefined) {
                                            this.creditBalance = parseFloat(statusData.credit_balance);
                                        }
                                    } else if (statusData.status === 'failed' || statusData.status === 'error') {
                                        failedJobs.add(jobId);
                                        this.error = statusData.error || 'Logo generation failed.';

                                        // Replace batch object to ensure Alpine detects the nested array change
                                        const batchIdx = this.logoBatches.findIndex(b => b.id === batchId);
                                        if (batchIdx !== -1) {
                                            this.logoBatches[batchIdx] = {
                                                ...this.logoBatches[batchIdx],
                                                images: [...this.logoBatches[batchIdx].images, {
                                                    key: `${jobId}-failed`,
                                                    url: null,
                                                    failed: true,
                                                    error: statusData.error || 'Generation failed',
                                                    seed: null,
                                                    isVector: this.outputFormat === 'vector',
                                                    metadata: this.logoBatches[batchIdx].metadata || {}
                                                }]
                                            };
                                        }

                                        if (statusData.credit_balance !== undefined) {
                                            this.creditBalance = parseFloat(statusData.credit_balance);
                                        }
                                    }
                                } catch (e) {
                                    console.error('Polling error:', e);
                                }
                            }
                        }

                        // Check if target batch has images
                        const finalBatchIdx = this.logoBatches.findIndex(b => b.id === batchId);
                        if (finalBatchIdx !== -1) {
                            const finalBatch = this.logoBatches[finalBatchIdx];
                            this.logoBatches[finalBatchIdx] = { ...finalBatch, loading: false };
                            if (finalBatch.images.length > 0) {
                                this.queueSimilarIdeasLookup();
                            }
                        }

                        this.generating = false;
                    } catch (err) {
	                        this.error = err.message || 'An error occurred while generating logos';
	                        this.generating = false;

	                        // Remove empty placeholder batches; keep batches that already contain job results.
	                        const finalBatchIdx = this.logoBatches.findIndex(b => b.id === batchId);
	                        if (finalBatchIdx !== -1) {
	                            if ((this.logoBatches[finalBatchIdx].images || []).length === 0) {
	                                this.logoBatches.splice(finalBatchIdx, 1);
	                            } else {
	                                this.logoBatches[finalBatchIdx] = { ...this.logoBatches[finalBatchIdx], loading: false };
	                            }
	                        }
	                    }
                },

                async saveLogo(url) {
                    try {
                        const response = await fetch('/domain-search/save-logo', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            },
                            body: JSON.stringify({ url })
                        });

                        const data = await response.json();
                        if (data.success) {
                            alert('Logo saved successfully!');
                        }
                    } catch (err) {
                        console.error('Save error:', err);
                    }
                },

                async convertToSvg(url) {
                    try {
                        const response = await fetch('/domain-search/convert-to-svg', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            },
                            body: JSON.stringify({ url })
                        });

                        const data = await response.json();
                        if (data.svg_url) {
                            window.open(data.svg_url, '_blank');
                        }
                    } catch (err) {
                        console.error('SVG conversion error:', err);
                    }
                },

                openInEditor(url) {
                    window.open(`/pdf-editor?logo_url=${encodeURIComponent(url)}`, '_blank');
                },

                async removeBackground(url) {
                    try {
                        // Check if it's an SVG (vector)
                        const isVector = url.toLowerCase().endsWith('.svg');
                        
                        if (isVector) {
                            // For SVG: Fetch, parse, remove backgrounds, and save
                            const svgResponse = await fetch(url);
                            const svgText = await svgResponse.text();
                            const parser = new DOMParser();
                            const svgDoc = parser.parseFromString(svgText, 'image/svg+xml');
                            const svgElement = svgDoc.documentElement;
                            
                            // Get viewBox for processing
                            const viewBoxValues = (svgElement.getAttribute('viewBox') || '0 0 512 512')
                                .split(/\s+/)
                                .map((v) => parseFloat(v));
                            
                            // Remove white backgrounds using the same logic as removeSvgBackground
                            this.removeWhiteBackgrounds(svgElement, viewBoxValues);
                            
                            // Remove editor background markers
                            const editorBg = svgElement.querySelectorAll('[data-editor-bg="true"]');
                            editorBg.forEach((el) => el.remove());
                            
                            // Remove full-page backgrounds more aggressively
                            const [vbX, vbY, vbWidth, vbHeight] = viewBoxValues;
                            
                            // Remove white background rects
                            const rects = svgElement.querySelectorAll('rect');
                            rects.forEach((rect) => {
                                const x = parseFloat(rect.getAttribute('x') || 0);
                                const y = parseFloat(rect.getAttribute('y') || 0);
                                const width = parseFloat(rect.getAttribute('width') || 0);
                                const height = parseFloat(rect.getAttribute('height') || 0);
                                const widthRatio = width / vbWidth;
                                const heightRatio = height / vbHeight;
                                const nearOrigin = Math.abs(x - vbX) <= 8 && Math.abs(y - vbY) <= 8;
                                const fullPage = widthRatio >= 0.95 && heightRatio >= 0.95;
                                if (nearOrigin && fullPage) {
                                    rect.remove();
                                }
                            });
                            
                            // Remove white background paths (check first element only)
                            const paths = svgElement.querySelectorAll('path');
                            if (paths.length > 0) {
                                const firstPath = paths[0];
                                const fill = firstPath.getAttribute('fill');
                                const d = firstPath.getAttribute('d');
                                
                                // Check if white
                                const fillLower = (fill || '').toLowerCase().trim();
                                const isWhite = fillLower === 'white' || 
                                               fillLower === '#fff' || 
                                               fillLower === '#ffffff' ||
                                               fillLower.match(/rgb\(25[0-5],?\s*25[0-5],?\s*25[0-5]\)/);
                                
                                if (isWhite && d) {
                                    // Check if it's a full-page rectangle path
                                    const rectPattern = /M\s*[\d.\-]+[\s,]+[\d.\-]+\s*L\s*[\d.\-]+[\s,]+[\d.\-]+\s*L\s*[\d.\-]+[\s,]+[\d.\-]+\s*L\s*[\d.\-]+[\s,]+[\d.\-]+\s*[LZ]/i;
                                    if (rectPattern.test(d)) {
                                        const coords = d.match(/[\d.\-]+/g);
                                        if (coords && coords.length >= 8) {
                                            const pathWidth = Math.max(parseFloat(coords[2]), parseFloat(coords[4]));
                                            const pathHeight = Math.max(parseFloat(coords[3]), parseFloat(coords[5]));
                                            const coversFullPage = (pathWidth >= vbWidth * 0.95 && pathHeight >= vbHeight * 0.95);
                                            if (coversFullPage) {
                                                firstPath.remove();
                                            }
                                        }
                                    }
                                }
                            }
                            
                            // Convert back to string and upload
                            const serializer = new XMLSerializer();
                            const processedSvg = serializer.serializeToString(svgElement);
                            
                            // Upload the processed SVG
                            const uploadResponse = await fetch('/domain-search/save-processed-svg', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                                },
                                body: JSON.stringify({ svg: processedSvg })
                            });
                            
                            const uploadData = await uploadResponse.json();
                            if (uploadData.url) {
                                // Add the new SVG to the most recent batch
                                if (this.logoBatches.length > 0) {
                                    this.logoBatches[0].images.push({ url: uploadData.url, isVector: true, metadata: {} });
                                } else {
                                    this.logoBatches.unshift({
                                        id: Date.now(),
                                        timestamp: new Date().toISOString(),
                                        images: [{ url: uploadData.url, isVector: true, metadata: {} }],
                                        loading: false,
                                        metadata: {}
                                    });
                                }
                            }
                        } else {
                            // For raster images: Use existing API
                            const response = await fetch('/domain-search/remove-background', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                                },
                                body: JSON.stringify({ url })
                            });

                            const data = await response.json();
                            if (data.url) {
                                // Add the new image to the most recent batch
                                if (this.logoBatches.length > 0) {
                                    this.logoBatches[0].images.push({ url: data.url, isVector: false, metadata: {} });
                                } else {
                                    this.logoBatches.unshift({
                                        id: Date.now(),
                                        timestamp: new Date().toISOString(),
                                        images: [{ url: data.url, isVector: false, metadata: {} }],
                                        loading: false,
                                        metadata: {}
                                    });
                                }
                            }
                        }
                    } catch (err) {
                        console.error('Background removal error:', err);
                        alert('Error removing background. Please try again.');
                    }
                },

                useSeed(seed) {
                    this.seed = seed;
                    alert('Seed ' + seed + ' will be used for next generation');
                },

                async useAsPrompt(url) {
                    try {
                        const response = await fetch('/domain-search/describe-logo', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            },
                            body: JSON.stringify({ url })
                        });

                        const data = await response.json();
                        if (data.description) {
                            this.logoPrompt = data.description;
                        }
                    } catch (err) {
                        console.error('Describe error:', err);
                    }
                },

                zoomImage(url) {
                    this.zoomImageUrl = url;
                },

                async openEditorTab() {
                    return false;
                },

                setCanvasSize() {
                    if (!this.editorSvgElement) return;
                    
                    let width, height;
                    
                    switch (this.canvasSize) {
                        case 'letter':
                            // 8.5" x 11" at 96 DPI
                            width = 816;
                            height = 1056;
                            break;
                        case 'business-card':
                            // 3.5" x 2" at 96 DPI
                            width = 336;
                            height = 192;
                            break;
                        case 'default':
                        default:
                            // Keep a roomy default workspace instead of snapping back to tiny source bounds.
                            const rootGroup = this.editorSvgElement.querySelector('g[id^="original-svg-"]');
                            const baseWidth = parseFloat(rootGroup?.getAttribute('data-original-width')) || 512;
                            const baseHeight = parseFloat(rootGroup?.getAttribute('data-original-height')) || 512;
                            width = Math.max(baseWidth, 900);
                            height = Math.max(baseHeight, 900);
                            break;
                    }
                    
                    // Get old canvas dimensions before changing
                    const oldViewBox = this.editorSvgElement.getAttribute('viewBox').split(' ');
                    const oldWidth = parseFloat(oldViewBox[2]);
                    const oldHeight = parseFloat(oldViewBox[3]);
                    
                    // Update viewBox to new canvas size
                    this.editorSvgElement.setAttribute('viewBox', `0 0 ${width} ${height}`);
                    
                    // Scale all groups to maintain relative size on new canvas
                    const groups = this.editorSvgElement.querySelectorAll('g[id^="original-svg-"], g[id^="imported-"]');
                    groups.forEach(group => {
                        // Get current transform
                        const transform = group.getAttribute('transform') || '';
                        let currentScale = 1;
                        let translateX = 0;
                        let translateY = 0;
                        
                        const scaleMatch = transform.match(/scale\(([^)]+)\)/);
                        const translateMatch = transform.match(/translate\(([^,]+),\s*([^)]+)\)/);
                        
                        if (scaleMatch) currentScale = parseFloat(scaleMatch[1]);
                        if (translateMatch) {
                            translateX = parseFloat(translateMatch[1]);
                            translateY = parseFloat(translateMatch[2]);
                        }
                        
                        // Calculate new scale based on canvas size change
                        // Make content fill 90% of the smaller dimension for larger display
                        const oldCanvasSize = Math.min(oldWidth, oldHeight);
                        const newCanvasSize = Math.min(width, height);
                        const scaleFactor = (newCanvasSize / oldCanvasSize) * 0.9;
                        
                        // Center the scaled content
                        const newTranslateX = (width - (oldWidth * scaleFactor)) / 2;
                        const newTranslateY = (height - (oldHeight * scaleFactor)) / 2;
                        
                        group.setAttribute('transform', `translate(${newTranslateX}, ${newTranslateY}) scale(${scaleFactor})`);
                    });
                    
                    // Maintain responsive sizing
                    this.editorSvgElement.setAttribute('width', '100%');
                    this.editorSvgElement.setAttribute('height', 'auto');
                },

                updateElementInteractivity() {
                    if (!this.editorSvgElement) return;
                    this.clearInlineOutlines();
                    
                    // In edit mode we only want child-element editing, not whole-group dragging.
                    const groups = this.editorSvgElement.querySelectorAll('g[id^="original-svg-"], g[id^="imported-"]');
                    groups.forEach(g => {
                        g.style.cursor = this.editMode ? 'default' : 'move';
                    });
                    
                    if (this.editMode) {
                        this.hideHoverMenu();
                        this.clearSelection();
                        this.makeElementsClickable();
                    } else {
                        this.removeElementClickHandlers();
                    }
                },

                makeElementsClickable() {
                    if (!this.editorSvgElement) return;
                    
                    const elements = this.editorSvgElement.querySelectorAll('path, circle, rect, ellipse, polygon, polyline, line, text');
                    
                    elements.forEach(el => {
                        if (el.tagName?.toLowerCase() === 'text' && el.closest('g[data-vectorized-text="1"]')) {
                            return;
                        }

                        // Remove existing listeners to avoid duplicates
                        const newEl = el.cloneNode(true);
                        el.parentNode.replaceChild(newEl, el);
                        
                        newEl.style.cursor = 'grab';
                        newEl.setAttribute('data-clickable', 'true');
                        
                        // Add click to select
                        newEl.addEventListener('click', (e) => {
                            e.stopPropagation();
                            this.selectElement(newEl);
                        });

                        // Double-click text to open full text editor instantly.
                        if (newEl.tagName?.toLowerCase() === 'text') {
                            newEl.addEventListener('dblclick', (e) => {
                                e.stopPropagation();
                                this.selectElement(newEl);
                                this.openTextEditorForSelected();
                            });
                        }
                        
                        // Make element draggable
                        this.makeElementDraggable(newEl);
                    });
                },

                removeElementClickHandlers() {
                    if (!this.editorSvgElement) return;
                    
                    const elements = this.editorSvgElement.querySelectorAll('[data-clickable="true"]');
                    elements.forEach(el => {
                        el.style.outline = '';
                        el.style.cursor = '';
                        el.removeAttribute('data-clickable');
                        // Clone to remove event listeners
                        const newEl = el.cloneNode(true);
                        newEl.style.outline = '';
                        el.parentNode.replaceChild(newEl, el);
                    });
                    
                    // Clear selection when switching to move mode
                    this.clearSelection();
                },

                parseGroupTransform(transform) {
                    let translateX = 0;
                    let translateY = 0;
                    let scale = 1;
                    if (!transform) return { translateX, translateY, scale };

                    const translateMatch = transform.match(/translate\(([^,]+),\s*([^)]+)\)/);
                    const scaleMatch = transform.match(/scale\(([^)]+)\)/);
                    if (translateMatch) {
                        translateX = parseFloat(translateMatch[1]) || 0;
                        translateY = parseFloat(translateMatch[2]) || 0;
                    }
                    if (scaleMatch) {
                        scale = parseFloat(scaleMatch[1]) || 1;
                    }

                    return { translateX, translateY, scale };
                },

                getSelectionBounds(element) {
                    try {
                        const bbox = element.getBBox();
                        
                        // For groups, parse their transform
                        if (element.tagName?.toLowerCase() === 'g') {
                            const t = this.parseGroupTransform(element.getAttribute('transform'));
                            return {
                                x: t.translateX + (bbox.x * t.scale),
                                y: t.translateY + (bbox.y * t.scale),
                                width: bbox.width * t.scale,
                                height: bbox.height * t.scale,
                            };
                        }
                        
                        // For individual elements in edit mode, account for their transform
                        if (this.editGroupMode && element.getAttribute('transform')) {
                            // Parse the element's transform
                            const transform = element.getAttribute('transform');
                            const translateMatch = transform.match(/translate\(([^,]+),\s*([^)]+)\)/);
                            
                            if (translateMatch) {
                                const translateX = parseFloat(translateMatch[1]);
                                const translateY = parseFloat(translateMatch[2]);
                                
                                // Also check parent group transform
                                const parentGroup = this.editingGroup;
                                if (parentGroup) {
                                    const parentTransform = this.parseGroupTransform(parentGroup.getAttribute('transform'));
                                    return {
                                        x: parentTransform.translateX + bbox.x + translateX,
                                        y: parentTransform.translateY + bbox.y + translateY,
                                        width: bbox.width * parentTransform.scale,
                                        height: bbox.height * parentTransform.scale,
                                    };
                                }
                                
                                return {
                                    x: bbox.x + translateX,
                                    y: bbox.y + translateY,
                                    width: bbox.width,
                                    height: bbox.height,
                                };
                            }
                        }

                        return { x: bbox.x, y: bbox.y, width: bbox.width, height: bbox.height };
                    } catch (error) {
                        return null;
                    }
                },

                getSelectedElementColor(element) {
                    if (!element) return '#d1d5db';
                    if (element.tagName?.toLowerCase() === 'g') {
                        const coloredChild = element.querySelector('[fill]:not([fill=\"none\"]), [stroke]:not([stroke=\"none\"])');
                        if (!coloredChild) return '#d1d5db';
                        return coloredChild.getAttribute('fill') || coloredChild.getAttribute('stroke') || '#d1d5db';
                    }
                    return element.getAttribute('fill') || element.getAttribute('stroke') || element.style.fill || element.style.stroke || '#d1d5db';
                },

                getSelectedTextElement() {
                    if (!this.selectedElements.length) return null;
                    const el = this.selectedElements[this.selectedElements.length - 1];
                    return el?.tagName?.toLowerCase() === 'text' ? el : null;
                },

                hasSelectedTextElement() {
                    return this.getSelectedTextElement() !== null;
                },

                syncTextEditorFromSelection() {
                    const el = this.getSelectedTextElement();
                    if (!el) {
                        this.selectedTextContent = '';
                        return;
                    }

                    const fontFamily = el.getAttribute('font-family') || el.style.fontFamily || this.editorFontFamily;
                    const fontSizeRaw = el.getAttribute('font-size') || el.style.fontSize || this.editorFontSize;
                    const fontWeightRaw = String(el.getAttribute('font-weight') || el.style.fontWeight || '').toLowerCase();
                    const fontStyleRaw = String(el.getAttribute('font-style') || el.style.fontStyle || '').toLowerCase();

                    const parsedSize = parseFloat(fontSizeRaw);
                    const parsedWeight = parseInt(fontWeightRaw, 10);

                    this.selectedTextContent = el.textContent || '';
                    this.selectedTextFontFamily = fontFamily;
                    this.selectedTextFontSize = Number.isFinite(parsedSize) ? parsedSize : 48;
                    this.selectedTextBold = fontWeightRaw === 'bold' || (!Number.isNaN(parsedWeight) && parsedWeight >= 600);
                    this.selectedTextItalic = fontStyleRaw === 'italic' || fontStyleRaw === 'oblique';
                },

                applySelectedTextChanges() {
                    const el = this.getSelectedTextElement();
                    if (!el) return;

                    const safeSize = Math.min(300, Math.max(8, parseFloat(this.selectedTextFontSize) || 48));
                    const nextText = String(this.selectedTextContent ?? '');

                    el.textContent = nextText;
                    el.setAttribute('font-family', this.selectedTextFontFamily || 'Arial');
                    el.setAttribute('font-size', String(safeSize));
                    el.setAttribute('font-weight', this.selectedTextBold ? '700' : '400');
                    el.setAttribute('font-style', this.selectedTextItalic ? 'italic' : 'normal');
                    el.setAttribute('data-layer-name', 'Text: ' + (nextText || 'Untitled').substring(0, 20));

                    this.updateLayers();
                    this.updateHoverMenuPosition();
                },

                toggleSelectedTextBold() {
                    this.selectedTextBold = !this.selectedTextBold;
                    this.applySelectedTextChanges();
                },

                toggleSelectedTextItalic() {
                    this.selectedTextItalic = !this.selectedTextItalic;
                    this.applySelectedTextChanges();
                },

                hideHoverMenu() {
                    this.hoverMenu.visible = false;
                },

                getElementScreenBounds(element) {
                    if (!element || typeof element.getBoundingClientRect !== 'function') return null;
                    const rect = element.getBoundingClientRect();
                    if (!Number.isFinite(rect.left) || !Number.isFinite(rect.top) || rect.width <= 0 || rect.height <= 0) {
                        return null;
                    }
                    return {
                        left: rect.left,
                        top: rect.top,
                        right: rect.right,
                        bottom: rect.bottom,
                        width: rect.width,
                        height: rect.height,
                    };
                },

                updateHoverMenuPosition() {
                    if (!this.selectedElements.length || !this.editorSvgElement) {
                        this.hideHoverMenu();
                        return;
                    }

                    const surfaceRect = null;
                    if (!surfaceRect) {
                        this.hideHoverMenu();
                        return;
                    }

                    // In edit mode, anchor to the exact selected element.
                    // Outside edit mode, anchor to the union of selected elements.
                    let anchorLeft;
                    let anchorTop;
                    let anchorRight;

                    if (this.editMode && this.selectedElements.length > 0) {
                        const anchorEl = this.selectedElements[this.selectedElements.length - 1];
                        const bounds = this.getElementScreenBounds(anchorEl);
                        if (!bounds) {
                            this.hideHoverMenu();
                            return;
                        }
                        anchorLeft = bounds.left;
                        anchorTop = bounds.top;
                        anchorRight = bounds.right;
                    } else {
                        let minLeft = Number.POSITIVE_INFINITY;
                        let minTop = Number.POSITIVE_INFINITY;
                        let maxRight = Number.NEGATIVE_INFINITY;
                        let found = false;

                        this.selectedElements.forEach((el) => {
                            const bounds = this.getElementScreenBounds(el);
                            if (!bounds) return;
                            minLeft = Math.min(minLeft, bounds.left);
                            minTop = Math.min(minTop, bounds.top);
                            maxRight = Math.max(maxRight, bounds.right);
                            found = true;
                        });

                        if (!found) {
                            this.hideHoverMenu();
                            return;
                        }

                        anchorLeft = minLeft;
                        anchorTop = minTop;
                        anchorRight = maxRight;
                    }

                    this.hoverMenu.x = ((anchorLeft + anchorRight) / 2) - surfaceRect.left;
                    this.hoverMenu.y = anchorTop - surfaceRect.top - 14;
                    this.hoverMenu.visible = true;
                },

                clearSelection() {
                    this.selectedElements.forEach((el) => {
                        if (!el) return;
                        el.style.outline = '';
                        if (typeof el.__hideResizeBox === 'function') {
                            el.__hideResizeBox();
                        }
                        // Hide bounding boxes
                        this.hideElementBoundingBox(el, 'selected');
                        this.hideElementBoundingBox(el, 'hover');
                    });
                    this.selectedElements = [];
                    this.editGroupMode = false;
                    this.editingGroup = null;
                    this.clearInlineOutlines();
                    this.hideHoverMenu();
                    this.syncTextEditorFromSelection();
                },

                clearInlineOutlines() {
                    if (!this.editorSvgElement) return;
                    const outlined = this.editorSvgElement.querySelectorAll('*');
                    outlined.forEach((el) => {
                        if (el?.style?.outline) {
                            el.style.outline = '';
                        }
                    });
                },

                selectMoveModeElements(elements) {
                    this.clearSelection();
                    this.selectedElements = elements.filter(Boolean);

                    if (this.selectedElements.length === 1 && typeof this.selectedElements[0].__showResizeBox === 'function') {
                        this.selectedElements[0].__showResizeBox();
                    }

                    if (this.selectedElements.length > 0) {
                        this.selectedElementColor = this.getSelectedElementColor(this.selectedElements[0]);
                        this.updateHoverMenuPosition();
                    }
                    this.syncTextEditorFromSelection();
                },

                selectMoveModeGroup(group) {
                    this.selectMoveModeElements([group]);
                },

                selectElement(element, addToSelection = false) {
                    // In editGroupMode, allow selection of child elements even if they're groups
                    // In normal mode, clicking a group should select the whole group
                    if (!this.editMode && !this.editGroupMode && element?.tagName?.toLowerCase() === 'g') {
                        this.selectMoveModeGroup(element);
                        return;
                    }

                    if (!addToSelection) {
                        // Clear previous selection
                        this.selectedElements.forEach(el => {
                            if (el) el.style.outline = '';
                        });
                        this.selectedElements = [];
                    }
                    
                    // Add element to selection
                    if (element && !this.selectedElements.includes(element)) {
                        this.selectedElements.push(element);
                        element.style.outline = '2px solid #8b5cf6';
                    }
                    
                    // Get current color from first selected element
                    if (this.selectedElements.length > 0) {
                        const firstEl = this.selectedElements[0];
                        const fill = firstEl.getAttribute('fill') || firstEl.style.fill;
                        const stroke = firstEl.getAttribute('stroke') || firstEl.style.stroke;
                        this.selectedElementColor = fill && fill !== 'none' ? fill : (stroke || '#000000');
                        this.updateHoverMenuPosition();
                    } else {
                        this.hideHoverMenu();
                    }
                    this.syncTextEditorFromSelection();
                },

                updateSelectedElementColor(newColor) {
                    if (this.selectedElements.length === 0) return;
                    
                    // Update the swatch color
                    this.selectedElementColor = newColor;
                    
                    this.selectedElements.forEach(element => {
                        const fill = element.getAttribute('fill');
                        const stroke = element.getAttribute('stroke');
                        
                        if (fill && fill !== 'none') {
                            element.setAttribute('fill', newColor);
                        }
                        if (stroke && stroke !== 'none') {
                            element.setAttribute('stroke', newColor);
                        }
                        
                        // Also check style
                        if (element.style.fill) {
                            element.style.fill = newColor;
                        }
                        if (element.style.stroke) {
                            element.style.stroke = newColor;
                        }
                    });
                },

                makeElementDraggable(element) {
                    // Clean up existing drag handlers if any
                    if (typeof element.__destroyElementDraggable === 'function') {
                        element.__destroyElementDraggable();
                    }
                    
                    let isDragging = false;
                    let hasDragged = false;
                    let startX, startY;
                    let currentTransform = { translateX: 0, translateY: 0 };
                    let rafId = null;
                    
                    // Parse existing transform if present
                    const existingTransform = element.getAttribute('transform');
                    if (existingTransform) {
                        const translateMatch = existingTransform.match(/translate\(([^,]+),\s*([^)]+)\)/);
                        if (translateMatch) {
                            currentTransform.translateX = parseFloat(translateMatch[1]) || 0;
                            currentTransform.translateY = parseFloat(translateMatch[2]) || 0;
                        }
                    }
                    
                    // Get parent group's scale to adjust drag speed
                    const getParentScale = () => {
                        let parent = element.parentElement;
                        while (parent && parent !== this.editorSvgElement) {
                            if (parent.tagName === 'g') {
                                const transform = parent.getAttribute('transform');
                                if (transform) {
                                    const parsed = this.parseGroupTransform(transform);
                                    if (parsed.scale !== 1) {
                                        return parsed.scale;
                                    }
                                }
                            }
                            parent = parent.parentElement;
                        }
                        return 1;
                    };
                    
                    const getCoords = (e) => {
                        const svg = this.editorSvgElement;
                        const pt = svg.createSVGPoint();
                        pt.x = e.clientX || e.touches?.[0]?.clientX || 0;
                        pt.y = e.clientY || e.touches?.[0]?.clientY || 0;
                        return pt.matrixTransform(svg.getScreenCTM().inverse());
                    };
                    
                    const onStart = (e) => {
                        // In editGroupMode, allow any child element to be dragged
                        // In normal editMode, only drag if element is selected
                        if (!this.editGroupMode && !this.selectedElements.includes(element)) return;
                        
                        isDragging = true;
                        hasDragged = false;
                        element.style.cursor = 'grabbing';
                        
                        const coords = getCoords(e);
                        startX = coords.x;
                        startY = coords.y;
                        
                        e.stopPropagation();
                        e.preventDefault();
                    };
                    
                    const onMove = (e) => {
                        if (!isDragging) return;
                        
                        hasDragged = true;
                        
                        // Cancel any pending animation frame
                        if (rafId) cancelAnimationFrame(rafId);
                        
                        rafId = requestAnimationFrame(() => {
                            const coords = getCoords(e);
                            let dx = coords.x - startX;
                            let dy = coords.y - startY;
                            
                            // Adjust for parent group's scale transform
                            const parentScale = getParentScale();
                            if (parentScale !== 1) {
                                dx = dx / parentScale;
                                dy = dy / parentScale;
                            }
                            
                            currentTransform.translateX += dx;
                            currentTransform.translateY += dy;
                            
                            // Build transform string, preserving other transforms
                            let transformStr = `translate(${currentTransform.translateX}, ${currentTransform.translateY})`;
                            
                            // Preserve scale, rotate, etc. if they exist
                            const existingTransform = element.getAttribute('transform') || '';
                            const scaleMatch = existingTransform.match(/scale\([^)]+\)/);
                            const rotateMatch = existingTransform.match(/rotate\([^)]+\)/);
                            
                            if (scaleMatch) transformStr += ` ${scaleMatch[0]}`;
                            if (rotateMatch) transformStr += ` ${rotateMatch[0]}`;
                            
                            element.setAttribute('transform', transformStr);
                            if (this.selectedElements.includes(element)) {
                                this.updateHoverMenuPosition();
                            }
                            
                            startX = coords.x;
                            startY = coords.y;
                        });
                        
                        e.preventDefault();
                    };
                    
                    const onEnd = () => {
                        if (isDragging) {
                            isDragging = false;
                            element.style.cursor = 'grab';
                            
                            if (rafId) {
                                cancelAnimationFrame(rafId);
                                rafId = null;
                            }
                            
                            // Update bounding boxes after dragging in edit mode
                            if (this.editGroupMode && element.__bboxId) {
                                // Check if this element has bounding boxes and redraw them
                                const hasHoverBox = this.editorSvgElement.querySelector(`[data-bbox-id="bbox-hover-${element.__bboxId}"]`);
                                const hasSelectedBox = this.editorSvgElement.querySelector(`[data-bbox-id="bbox-selected-${element.__bboxId}"]`);
                                
                                if (hasHoverBox) {
                                    this.hideElementBoundingBox(element, 'hover');
                                    this.showElementBoundingBox(element, 'hover');
                                }
                                if (hasSelectedBox) {
                                    this.hideElementBoundingBox(element, 'selected');
                                    this.showElementBoundingBox(element, 'selected');
                                }
                                
                                // Update hover menu position if this element is selected
                                if (this.selectedElements.includes(element)) {
                                    this.updateHoverMenuPosition();
                                }
                            }
                        }
                    };
                    
                    // Prevent click event if we actually dragged
                    const preventClickIfDragged = (e) => {
                        if (hasDragged) {
                            e.stopPropagation();
                            e.preventDefault();
                            hasDragged = false;
                        }
                    };
                    
                    element.addEventListener('mousedown', onStart);
                    element.addEventListener('touchstart', onStart);
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('touchmove', onMove);
                    document.addEventListener('mouseup', onEnd);
                    document.addEventListener('touchend', onEnd);
                    element.addEventListener('click', preventClickIfDragged, true);
                    
                    // Store cleanup function
                    element.__destroyElementDraggable = () => {
                        element.removeEventListener('mousedown', onStart);
                        element.removeEventListener('touchstart', onStart);
                        document.removeEventListener('mousemove', onMove);
                        document.removeEventListener('touchmove', onMove);
                        document.removeEventListener('mouseup', onEnd);
                        document.removeEventListener('touchend', onEnd);
                        element.removeEventListener('click', preventClickIfDragged, true);
                    };
                },

                startMarqueeSelection(e) {
                    if (!this.editorSvgElement) return;
                    
                    // Get SVG coordinates
                    const pt = this.editorSvgElement.createSVGPoint();
                    pt.x = e.clientX;
                    pt.y = e.clientY;
                    const svgPt = pt.matrixTransform(this.editorSvgElement.getScreenCTM().inverse());
                    
                    // Initialize selection
                    this.isSelecting = true;
                    this.selectionStartX = svgPt.x;
                    this.selectionStartY = svgPt.y;
                    this.selectionEndX = svgPt.x;
                    this.selectionEndY = svgPt.y;
                    
                    // Show selection box
                    if (this.selectionRectElement) {
                        this.editorSvgElement.appendChild(this.selectionRectElement);
                        this.selectionRectElement.style.display = 'block';
                        this.selectionRectElement.style.opacity = '1';
                        this.selectionRectElement.setAttribute('x', this.selectionStartX);
                        this.selectionRectElement.setAttribute('y', this.selectionStartY);
                        this.selectionRectElement.setAttribute('width', 0);
                        this.selectionRectElement.setAttribute('height', 0);
                    }
                    
                    // Add mousemove and mouseup listeners
                    const onMove = (e) => this.updateMarqueeSelection(e);
                    const onEnd = (e) => {
                        this.endMarqueeSelection(e);
                        document.removeEventListener('mousemove', onMove);
                        document.removeEventListener('mouseup', onEnd);
                    };
                    
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('mouseup', onEnd);
                    
                    e.preventDefault();
                },

                updateMarqueeSelection(e) {
                    if (!this.isSelecting || !this.editorSvgElement || !this.selectionRectElement) return;
                    
                    // Get SVG coordinates
                    const pt = this.editorSvgElement.createSVGPoint();
                    pt.x = e.clientX;
                    pt.y = e.clientY;
                    const svgPt = pt.matrixTransform(this.editorSvgElement.getScreenCTM().inverse());
                    
                    this.selectionEndX = svgPt.x;
                    this.selectionEndY = svgPt.y;
                    
                    // Calculate rectangle dimensions (handle negative width/height)
                    const x = Math.min(this.selectionStartX, this.selectionEndX);
                    const y = Math.min(this.selectionStartY, this.selectionEndY);
                    const width = Math.abs(this.selectionEndX - this.selectionStartX);
                    const height = Math.abs(this.selectionEndY - this.selectionStartY);
                    
                    // Update selection box
                    this.selectionRectElement.setAttribute('x', x);
                    this.selectionRectElement.setAttribute('y', y);
                    this.selectionRectElement.setAttribute('width', width);
                    this.selectionRectElement.setAttribute('height', height);
                },

                endMarqueeSelection(e) {
                    if (!this.isSelecting || !this.editorSvgElement) return;
                    
                    this.isSelecting = false;
                    
                    // Hide selection box
                    if (this.selectionRectElement) {
                        this.selectionRectElement.style.display = 'none';
                    }
                    
                    // Calculate selection rectangle
                    const x = Math.min(this.selectionStartX, this.selectionEndX);
                    const y = Math.min(this.selectionStartY, this.selectionEndY);
                    const width = Math.abs(this.selectionEndX - this.selectionStartX);
                    const height = Math.abs(this.selectionEndY - this.selectionStartY);
                    
                    // If selection box is too small (just a click), clear selection
                    if (width < 5 && height < 5) {
                        this.clearSelection();
                        return;
                    }

                    if (this.editMode) {
                        const elements = this.editorSvgElement.querySelectorAll('[data-clickable=\"true\"]');

                        this.selectedElements.forEach(el => {
                            if (el) el.style.outline = '';
                        });
                        this.selectedElements = [];

                        elements.forEach(el => {
                            try {
                                const bbox = el.getBBox();
                                const intersects = !(
                                    bbox.x + bbox.width < x ||
                                    bbox.x > x + width ||
                                    bbox.y + bbox.height < y ||
                                    bbox.y > y + height
                                );

                        if (intersects) {
                            this.selectedElements.push(el);
                            if (this.editMode) {
                                el.style.outline = '2px solid #8b5cf6';
                            }
                        }
                            } catch (err) {
                                console.warn('Could not get bbox for element', el, err);
                            }
                        });

                        if (this.selectedElements.length > 0) {
                            const firstEl = this.selectedElements[0];
                            const fill = firstEl.getAttribute('fill') || firstEl.style.fill;
                            const stroke = firstEl.getAttribute('stroke') || firstEl.style.stroke;
                            this.selectedElementColor = fill && fill !== 'none' ? fill : (stroke || '#000000');
                        }
                        return;
                    }

                    const groups = this.editorSvgElement.querySelectorAll('g[id^="original-svg-"], g[id^="imported-"]');
                    const selectedGroups = [];
                    groups.forEach((group) => {
                        const bbox = this.getSelectionBounds(group);
                        if (!bbox) return;
                        const intersects = !(
                            bbox.x + bbox.width < x ||
                            bbox.x > x + width ||
                            bbox.y + bbox.height < y ||
                            bbox.y > y + height
                        );
                        if (intersects) selectedGroups.push(group);
                    });

                    this.selectMoveModeElements(selectedGroups);
                },

                openReplaceMenu() {
                    if (!this.selectedElements.length) return;
                    this.replaceTargetElement = this.selectedElements[0];
                    this.showImportModal = true;
                },

                duplicateSelectedLogos() {
                    if (!this.editorSvgElement || this.selectedElements.length === 0) return;
                    const clones = [];
                    this.selectedElements.forEach((el, index) => {
                        if (el.tagName?.toLowerCase() !== 'g') return;
                        const clone = el.cloneNode(true);
                        clone.setAttribute('id', `imported-${Date.now()}-${index}`);
                        const t = this.parseGroupTransform(clone.getAttribute('transform'));
                        clone.setAttribute('transform', `translate(${t.translateX + 30}, ${t.translateY + 30}) scale(${t.scale})`);
                        this.editorSvgElement.appendChild(clone);
                        this.makeGroupDraggable(clone);
                        clones.push(clone);
                    });

                    this.selectMoveModeElements(clones);
                    this.updateLayers();
                },

                moveElementBackward() {
                    if (!this.editorSvgElement || this.selectedElements.length === 0) return;
                    
                    this.selectedElements.forEach((el) => {
                        const parent = el.parentElement;
                        if (!parent) return;
                        
                        // Get the previous sibling (element before this one)
                        const previousSibling = el.previousElementSibling;
                        
                        // Can't move back if already at the beginning or previous is selection rect
                        if (!previousSibling || previousSibling.id === 'selection-rect') return;
                        
                        // Insert current element before the previous sibling
                        parent.insertBefore(el, previousSibling);
                    });
                    
                    this.updateLayers();
                    this.updateHoverMenuPosition();
                },

                moveElementForward() {
                    if (!this.editorSvgElement || this.selectedElements.length === 0) return;
                    
                    this.selectedElements.forEach((el) => {
                        const parent = el.parentElement;
                        if (!parent) return;
                        
                        // Get the next sibling (element after this one)
                        const nextSibling = el.nextElementSibling;
                        
                        // Can't move forward if already at the end or next is selection rect
                        if (!nextSibling || nextSibling.id === 'selection-rect') return;
                        
                        // Insert current element after the next sibling
                        if (nextSibling.nextElementSibling) {
                            parent.insertBefore(el, nextSibling.nextElementSibling);
                        } else {
                            parent.appendChild(el);
                        }
                    });
                    
                    this.updateLayers();
                    this.updateHoverMenuPosition();
                },

                makeHolesTransparent() {
                    if (!this.editorSvgElement || this.selectedElements.length === 0) return;
                    
                    let processedCount = 0;
                    
                    this.selectedElements.forEach((el) => {
                        // Get all paths in this element
                        const paths = Array.from(el.querySelectorAll('path, rect, circle, ellipse, polygon, polyline'));
                        
                        // Separate white-filled (holes) from colored (letters)
                        const whitePaths = [];
                        const coloredPaths = [];
                        
                        paths.forEach(path => {
                            const fill = path.getAttribute('fill') || '';
                            const styleFill = path.style.fill || '';
                            
                            const isWhite = 
                                fill === 'rgb(255,255,255)' || 
                                fill === '#ffffff' || 
                                fill === '#fff' || 
                                fill === 'white' ||
                                styleFill === 'rgb(255,255,255)' || 
                                styleFill === '#ffffff' || 
                                styleFill === '#fff' || 
                                styleFill === 'white';
                            
                            if (isWhite) {
                                whitePaths.push(path);
                            } else if (fill !== 'none' && fill !== '' && fill !== 'transparent') {
                                coloredPaths.push(path);
                            }
                        });
                        
                        if (whitePaths.length === 0) {
                            console.log('No white-filled holes found in this element');
                            return;
                        }
                        
                        // Create a unique mask ID
                        const maskId = `mask-holes-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
                        
                        // Create a mask element
                        const mask = document.createElementNS('http://www.w3.org/2000/svg', 'mask');
                        mask.setAttribute('id', maskId);
                        
                        // Add a white rectangle as the base (everything visible)
                        const whiteBase = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                        whiteBase.setAttribute('x', '-100%');
                        whiteBase.setAttribute('y', '-100%');
                        whiteBase.setAttribute('width', '300%');
                        whiteBase.setAttribute('height', '300%');
                        whiteBase.setAttribute('fill', 'white');
                        mask.appendChild(whiteBase);
                        
                        // Add white paths as black in the mask (to cut out those areas)
                        whitePaths.forEach(whitePath => {
                            const maskPath = whitePath.cloneNode(true);
                            maskPath.setAttribute('fill', 'black'); // Black = transparent in mask
                            maskPath.removeAttribute('style');
                            mask.appendChild(maskPath);
                        });
                        
                        // Add mask to SVG defs
                        let defs = this.editorSvgElement.querySelector('defs');
                        if (!defs) {
                            defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
                            this.editorSvgElement.insertBefore(defs, this.editorSvgElement.firstChild);
                        }
                        defs.appendChild(mask);
                        
                        // Apply mask to colored paths
                        coloredPaths.forEach(coloredPath => {
                            coloredPath.setAttribute('mask', `url(#${maskId})`);
                        });
                        
                        // Remove the white paths (they're now in the mask)
                        whitePaths.forEach(whitePath => {
                            whitePath.remove();
                        });
                        
                        processedCount++;
                    });
                    
                    console.log(`Created masks for ${processedCount} elements to make letter holes transparent`);
                    
                    if (processedCount > 0) {
                        this.updateLayers();
                    }
                },

                groupSelectedLogos() {
                    if (!this.editorSvgElement || this.selectedElements.length < 2) return;
                    const selectedGroups = this.selectedElements.filter((el) => el.tagName?.toLowerCase() === 'g');
                    if (selectedGroups.length < 2) return;

                    const wrapper = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                    wrapper.setAttribute('id', `imported-${Date.now()}`);
                    wrapper.setAttribute('data-layer-name', 'Grouped Logos');
                    this.editorSvgElement.appendChild(wrapper);
                    selectedGroups.forEach((group) => wrapper.appendChild(group));

                    this.makeGroupDraggable(wrapper);
                    this.selectMoveModeElements([wrapper]);
                    this.updateLayers();
                },

                enterEditGroupMode() {
                    if (!this.selectedElements.length || !this.editorSvgElement) return;
                    
                    const group = this.selectedElements[0];
                    if (group.tagName !== 'g' || group.children.length <= 1) return;
                    
                    // Store reference to the group being edited
                    this.editingGroup = group;
                    this.editGroupMode = true;
                    
                    // Clear current selection outlines
                    this.selectedElements.forEach(el => {
                        if (el && typeof el.__hideResizeBox === 'function') {
                            el.__hideResizeBox();
                        }
                    });
                    
                    // Get all child elements
                    const childElements = Array.from(group.children).filter(child => 
                        child.tagName === 'path' || child.tagName === 'circle' || 
                        child.tagName === 'rect' || child.tagName === 'ellipse' || 
                        child.tagName === 'polygon' || child.tagName === 'g' ||
                        child.tagName === 'text'
                    );
                    
                    // Clear selection initially - user will click to select
                    this.selectedElements = [];
                    
                    // Add hover and click behavior to each child element
                    childElements.forEach(el => {
                        if (el) {
                            el.style.cursor = 'pointer';
                            
                            // Make each child element individually draggable
                            this.makeElementDraggable(el);
                            
                            // Add hover effect to show bounding box
                            const onMouseEnter = () => {
                                if (!this.selectedElements.includes(el)) {
                                    this.showElementBoundingBox(el, 'hover');
                                }
                            };
                            
                            const onMouseLeave = () => {
                                if (!this.selectedElements.includes(el)) {
                                    this.hideElementBoundingBox(el, 'hover');
                                }
                            };
                            
                            // Add click to select
                            const onClick = (e) => {
                                e.stopPropagation();
                                this.selectEditModeElement(el);
                            };
                            
                            el.addEventListener('mouseenter', onMouseEnter);
                            el.addEventListener('mouseleave', onMouseLeave);
                            el.addEventListener('click', onClick);
                            
                            // Store cleanup function
                            el.__cleanupEditMode = () => {
                                el.removeEventListener('mouseenter', onMouseEnter);
                                el.removeEventListener('mouseleave', onMouseLeave);
                                el.removeEventListener('click', onClick);
                                this.hideElementBoundingBox(el, 'hover');
                                this.hideElementBoundingBox(el, 'selected');
                            };
                        }
                    });
                    
                    this.hideHoverMenu();
                },

                selectEditModeElement(element) {
                    if (!this.editGroupMode) return;
                    
                    // Clear previous selection
                    this.selectedElements.forEach(el => {
                        this.hideElementBoundingBox(el, 'selected');
                        this.hideElementBoundingBox(el, 'hover');
                    });
                    
                    // Select new element
                    this.selectedElements = [element];
                    this.showElementBoundingBox(element, 'selected');
                    
                    // Update color from selected element
                    const fill = element.getAttribute('fill') || element.style.fill;
                    const stroke = element.getAttribute('stroke') || element.style.stroke;
                    this.selectedElementColor = fill && fill !== 'none' ? fill : (stroke || '#000000');
                    this.syncTextEditorFromSelection();
                    
                    // Show and position hover menu
                    this.hoverMenu.visible = true;
                    this.updateHoverMenuPosition();
                },

                showElementBoundingBox(element, type = 'hover') {
                    if (!element) return;
                    
                    // Use element reference to track boxes
                    if (!element.__bboxId) {
                        element.__bboxId = `el-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
                    }
                    const boxId = `bbox-${type}-${element.__bboxId}`;
                    
                    // Remove existing box of this type for this element
                    const existing = this.editorSvgElement.querySelector(`[data-bbox-id="${boxId}"]`);
                    if (existing) existing.remove();
                    
                    try {
                        const bbox = element.getBBox();
                        const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                        
                        rect.setAttribute('data-bbox-id', boxId);
                        rect.setAttribute('x', bbox.x - 2);
                        rect.setAttribute('y', bbox.y - 2);
                        rect.setAttribute('width', bbox.width + 4);
                        rect.setAttribute('height', bbox.height + 4);
                        rect.setAttribute('fill', 'none');
                        rect.setAttribute('stroke', type === 'selected' ? '#8b5cf6' : '#3b82f6');
                        rect.setAttribute('stroke-width', type === 'selected' ? '2' : '1');
                        rect.setAttribute('stroke-dasharray', type === 'selected' ? '0' : '5,5');
                        rect.style.pointerEvents = 'none';
                        
                        // Apply both the parent group's transform and the element's own transform
                        const parentTransform = this.editingGroup?.getAttribute('transform') || '';
                        const elementTransform = element.getAttribute('transform') || '';
                        
                        // Combine transforms: parent first, then element
                        let combinedTransform = '';
                        if (parentTransform && elementTransform) {
                            combinedTransform = `${parentTransform} ${elementTransform}`;
                        } else if (parentTransform) {
                            combinedTransform = parentTransform;
                        } else if (elementTransform) {
                            combinedTransform = elementTransform;
                        }
                        
                        if (combinedTransform) {
                            rect.setAttribute('transform', combinedTransform);
                        }
                        
                        this.editorSvgElement.appendChild(rect);
                    } catch (err) {
                        console.warn('Could not create bounding box:', err);
                    }
                },

                hideElementBoundingBox(element, type = 'hover') {
                    if (!element || !element.__bboxId) return;
                    
                    const boxId = `bbox-${type}-${element.__bboxId}`;
                    const existing = this.editorSvgElement?.querySelector(`[data-bbox-id="${boxId}"]`);
                    if (existing) existing.remove();
                },

                exitEditGroupMode() {
                    if (!this.editGroupMode) return;
                    
                    this.editGroupMode = false;
                    
                    // Get all child elements from the editing group
                    const childElements = this.editingGroup ? Array.from(this.editingGroup.children) : [];
                    
                    // Clean up all child elements
                    childElements.forEach(el => {
                        if (el) {
                            el.style.outline = '';
                            el.style.cursor = '';
                            // Clean up drag handlers
                            if (typeof el.__destroyElementDraggable === 'function') {
                                el.__destroyElementDraggable();
                            }
                            // Clean up edit mode listeners
                            if (typeof el.__cleanupEditMode === 'function') {
                                el.__cleanupEditMode();
                            }
                        }
                    });
                    
                    // Also clean up selected elements
                    this.selectedElements.forEach(el => {
                        if (el) {
                            el.style.outline = '';
                            el.style.cursor = '';
                        }
                    });
                    
                    // Re-select the parent group
                    if (this.editingGroup) {
                        this.selectMoveModeElements([this.editingGroup]);
                        this.editingGroup = null;
                    } else if (this.selectedElements.length > 0) {
                        const parentGroup = this.selectedElements[0].parentElement;
                        if (parentGroup && parentGroup.tagName === 'g') {
                            this.selectMoveModeElements([parentGroup]);
                        } else {
                            this.clearSelection();
                        }
                    }
                },

                buildStarPoints(cx, cy, outerRadius, innerRadius, points = 5) {
                    const pts = [];
                    const step = Math.PI / points;
                    let angle = -Math.PI / 2;

                    for (let i = 0; i < points * 2; i++) {
                        const r = i % 2 === 0 ? outerRadius : innerRadius;
                        const x = cx + Math.cos(angle) * r;
                        const y = cy + Math.sin(angle) * r;
                        pts.push(`${x},${y}`);
                        angle += step;
                    }

                    return pts.join(' ');
                },

                addShapeToSvg() {
                    if (!this.editorSvgElement) return;

                    const viewBox = this.editorSvgElement.getAttribute('viewBox')?.split(' ') || [0, 0, 512, 512];
                    const width = parseFloat(viewBox[2]) || 512;
                    const height = parseFloat(viewBox[3]) || 512;
                    const centerX = width / 2;
                    const centerY = height / 2;

                    const size = Math.max(20, Math.min(400, parseFloat(this.editorShapeSize) || 120));
                    const strokeWidth = Math.max(0, Math.min(20, parseFloat(this.editorShapeStrokeWidth) || 0));
                    const fill = this.normalizeHexColor(this.editorShapeFill, '#38BDF8');
                    const stroke = this.normalizeHexColor(this.editorShapeStroke, '#0F172A');

                    const wrapper = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                    wrapper.setAttribute('id', `imported-${Date.now()}`);
                    wrapper.setAttribute('data-layer-name', `${this.editorShapeType[0].toUpperCase()}${this.editorShapeType.slice(1)} Shape`);

                    let shapeEl = null;
                    const half = size / 2;

                    if (this.editorShapeType === 'line') {
                        shapeEl = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                        shapeEl.setAttribute('x1', centerX - half);
                        shapeEl.setAttribute('y1', centerY);
                        shapeEl.setAttribute('x2', centerX + half);
                        shapeEl.setAttribute('y2', centerY);
                        shapeEl.setAttribute('fill', 'none');
                    } else if (this.editorShapeType === 'rectangle') {
                        shapeEl = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                        shapeEl.setAttribute('x', centerX - half);
                        shapeEl.setAttribute('y', centerY - half);
                        shapeEl.setAttribute('width', size);
                        shapeEl.setAttribute('height', size);
                        shapeEl.setAttribute('fill', fill);
                    } else if (this.editorShapeType === 'circle') {
                        shapeEl = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                        shapeEl.setAttribute('cx', centerX);
                        shapeEl.setAttribute('cy', centerY);
                        shapeEl.setAttribute('r', half);
                        shapeEl.setAttribute('fill', fill);
                    } else if (this.editorShapeType === 'triangle') {
                        shapeEl = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
                        const points = [
                            `${centerX},${centerY - half}`,
                            `${centerX - half},${centerY + half}`,
                            `${centerX + half},${centerY + half}`,
                        ].join(' ');
                        shapeEl.setAttribute('points', points);
                        shapeEl.setAttribute('fill', fill);
                    } else if (this.editorShapeType === 'star') {
                        shapeEl = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
                        shapeEl.setAttribute('points', this.buildStarPoints(centerX, centerY, half, half * 0.45, 5));
                        shapeEl.setAttribute('fill', fill);
                    }

                    if (!shapeEl) return;

                    shapeEl.setAttribute('stroke', stroke);
                    shapeEl.setAttribute('stroke-width', String(strokeWidth));
                    shapeEl.style.cursor = 'move';
                    wrapper.appendChild(shapeEl);
                    this.editorSvgElement.appendChild(wrapper);

                    this.makeGroupDraggable(wrapper);
                    this.selectMoveModeElements([wrapper]);
                    this.updateLayers();
                },

                addTextToSvg() {
                    if (!this.editorText || !this.editorSvgElement) return;
                    
                    const viewBox = this.editorSvgElement.getAttribute('viewBox')?.split(' ') || [0, 0, 512, 512];
                    const width = parseFloat(viewBox[2]);
                    const height = parseFloat(viewBox[3]);
                    
                    const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                    text.setAttribute('x', width / 2);
                    text.setAttribute('y', height - 50);
                    text.setAttribute('text-anchor', 'middle');
                    text.setAttribute('font-family', this.editorFontFamily);
                    text.setAttribute('font-size', this.editorFontSize);
                    text.setAttribute('fill', this.editorTextColor);
                    text.setAttribute('font-weight', this.editorFontBold ? '700' : '400');
                    text.setAttribute('font-style', this.editorFontItalic ? 'italic' : 'normal');
                    text.style.cursor = 'move';
                    text.textContent = this.editorText;
                    if (this.editorTextUseVector) {
                        const wrapper = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                        wrapper.setAttribute('id', `imported-${Date.now()}`);
                        wrapper.setAttribute('data-layer-name', 'Vector Text: ' + this.editorText.substring(0, 20));
                        wrapper.setAttribute('data-vectorized-text', '1');
                        wrapper.appendChild(text);
                        this.editorSvgElement.appendChild(wrapper);
                        this.makeGroupDraggable(wrapper);
                        this.selectMoveModeElements([wrapper]);
                    } else {
                        text.setAttribute('data-layer-name', 'Text: ' + this.editorText.substring(0, 20));
                        this.makeDraggable(text);
                        this.editorSvgElement.appendChild(text);
                    }
                    this.updateLayers();
                    
                    this.editorText = '';
                    this.editorTextUseVector = false;
                },

                updateLayers() {
                    if (!this.editorSvgElement) return;
                    
                    // Only show top-level groups (imported vectors) and standalone text elements
                    const directChildren = Array.from(this.editorSvgElement.children);
                    const layerElements = directChildren.filter(el => {
                        // Show groups (imported vectors) or text elements added directly
                        return el.tagName === 'g' || el.tagName === 'text';
                    });
                    
                    this.svgLayers = layerElements.map((el, index) => {
                        let layerName = el.getAttribute('data-layer-name');
                        
                        // If no custom name, generate one based on element type
                        if (!layerName) {
                            if (el.tagName === 'g') {
                                layerName = el.id || 'Group ' + (index + 1);
                            } else {
                                layerName = 'Text ' + (index + 1);
                            }
                        }
                        
                        return {
                            id: Date.now() + index,
                            element: el,
                            name: layerName,
                            visible: el.style.display !== 'none',
                            isGroup: el.tagName === 'g'
                        };
                    });
                },

                selectLayerElement(element) {
                    this.selectElement(element);
                },

                selectLayer(layerId) {
                    const layer = this.svgLayers.find(l => l.id === layerId);
                    if (layer && layer.element) {
                        this.selectElement(layer.element);
                        // If element has click handler for showing resize box
                        if (layer.element._clickHandler) {
                            layer.element._clickHandler({ stopPropagation: () => {} });
                        }
                    }
                },

                toggleLayerVisibility(layerId) {
                    const layer = this.svgLayers.find(l => l.id === layerId);
                    if (layer) {
                        layer.visible = !layer.visible;
                        layer.element.style.display = layer.visible ? '' : 'none';
                    }
                },

                deleteLayer(layerId) {
                    const layer = this.svgLayers.find(l => l.id === layerId);
                    if (layer) {
                        // Save to undo stack before deleting
                        const element = layer.element;
                        const parent = element.parentNode;
                        const nextSibling = element.nextSibling;
                        const clonedElement = element.cloneNode(true);
                        
                        this.undoStack.push({
                            batch: [{
                                element: clonedElement,
                                parent: parent,
                                nextSibling: nextSibling
                            }],
                            timestamp: Date.now()
                        });
                        
                        // Limit undo stack to 10 items
                        if (this.undoStack.length > 10) {
                            this.undoStack.shift();
                        }
                        
                        // Remove from selection if selected
                        const index = this.selectedElements.indexOf(layer.element);
                        if (index > -1) {
                            this.selectedElements.splice(index, 1);
                        }
                        layer.element.remove();
                        this.updateLayers();
                        this.syncTextEditorFromSelection();
                    }
                },

                makeDraggable(element) {
                    let isDragging = false;
                    let startX, startY, initialX, initialY;
                    
                    const getCoords = (e) => {
                        const svg = this.editorSvgElement;
                        const pt = svg.createSVGPoint();
                        pt.x = e.clientX || e.touches?.[0]?.clientX || 0;
                        pt.y = e.clientY || e.touches?.[0]?.clientY || 0;
                        return pt.matrixTransform(svg.getScreenCTM().inverse());
                    };
                    
                    const onStart = (e) => {
                        isDragging = true;
                        element.style.cursor = 'grabbing';
                        
                        const coords = getCoords(e);
                        startX = coords.x;
                        startY = coords.y;
                        initialX = parseFloat(element.getAttribute('x') || 0);
                        initialY = parseFloat(element.getAttribute('y') || 0);
                        
                        e.preventDefault();
                    };
                    
                    const onMove = (e) => {
                        if (!isDragging) return;
                        
                        const coords = getCoords(e);
                        const dx = coords.x - startX;
                        const dy = coords.y - startY;
                        
                        element.setAttribute('x', initialX + dx);
                        element.setAttribute('y', initialY + dy);
                        
                        e.preventDefault();
                    };
                    
                    const onEnd = () => {
                        isDragging = false;
                        element.style.cursor = 'move';
                    };
                    
                    element.addEventListener('mousedown', onStart);
                    element.addEventListener('touchstart', onStart);
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('touchmove', onMove);
                    document.addEventListener('mouseup', onEnd);
                    document.addEventListener('touchend', onEnd);
                },

                downloadEditedSvg() {
                    if (!this.editorSvgElement) return;
                    
                    // Clone the SVG to avoid modifying the original
                    const svgClone = this.editorSvgElement.cloneNode(true);
                    
                    // Remove all resize boxes and selection box (UI elements)
                    const resizeBoxes = svgClone.querySelectorAll('.resize-box');
                    resizeBoxes.forEach(box => box.remove());
                    const selectionBoxes = svgClone.querySelectorAll('.selection-box');
                    selectionBoxes.forEach(box => box.remove());
                    
                    const serializer = new XMLSerializer();
                    const svgString = serializer.serializeToString(svgClone);
                    
                    const blob = new Blob([svgString], { type: 'image/svg+xml' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'edited-logo-' + Date.now() + '.svg';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                },

                deleteSelectedElement() {
                    if (this.selectedElements.length === 0) return;
                    
                    // Save all selected elements to undo stack before deleting
                    const deletedBatch = [];
                    
                    this.selectedElements.forEach(element => {
                        const parent = element.parentNode;
                        const nextSibling = element.nextSibling;
                        
                        // Clone the element to preserve it
                        const clonedElement = element.cloneNode(true);
                        
                        deletedBatch.push({
                            element: clonedElement,
                            parent: parent,
                            nextSibling: nextSibling
                        });
                        
                        // Remove the element itself
                        element.remove();
                    });
                    
                    // Store batch in undo stack
                    this.undoStack.push({
                        batch: deletedBatch,
                        timestamp: Date.now()
                    });
                    
                    // Limit undo stack to 10 items
                    if (this.undoStack.length > 10) {
                        this.undoStack.shift();
                    }
                    
                    // Remove any resize boxes
                    const resizeBoxes = this.editorSvgElement.querySelectorAll('.resize-box');
                    resizeBoxes.forEach(box => box.remove());
                    
                    // Clear selection
                    this.clearSelection();
                    
                    // Update layers list
                    this.updateLayers();
                },

                undoDelete() {
                    if (this.undoStack.length === 0) return;
                    
                    const lastDeleted = this.undoStack.pop();
                    
                    // Clear current selection
                    this.clearSelection();
                    
                    // Restore all elements in the batch
                    if (lastDeleted.batch) {
                        lastDeleted.batch.forEach(item => {
                            // Restore the element to its original position
                            if (item.nextSibling) {
                                item.parent.insertBefore(item.element, item.nextSibling);
                            } else {
                                item.parent.appendChild(item.element);
                            }
                            
                            // Make it draggable again if it's a group
                            if (item.element.tagName === 'g') {
                                this.makeGroupDraggable(item.element);
                            }
                            
                            // Add to selection
                            this.selectedElements.push(item.element);
                            if (this.editMode) {
                                item.element.style.outline = '2px solid #8b5cf6';
                            }
                        });
                    } else {
                        // Legacy support for old single-element undo format
                        if (lastDeleted.nextSibling) {
                            lastDeleted.parent.insertBefore(lastDeleted.element, lastDeleted.nextSibling);
                        } else {
                            lastDeleted.parent.appendChild(lastDeleted.element);
                        }
                        
                        if (lastDeleted.element.tagName === 'g') {
                            this.makeGroupDraggable(lastDeleted.element);
                        }
                        
                        this.selectedElements.push(lastDeleted.element);
                        if (this.editMode) {
                            lastDeleted.element.style.outline = '2px solid #8b5cf6';
                        }
                    }

                    if (!this.editMode) {
                        const moveGroups = this.selectedElements.filter((el) => el.tagName?.toLowerCase() === 'g');
                        this.selectMoveModeElements(moveGroups);
                    }
                    
                    // Update layers list
                    this.updateLayers();
                    this.syncTextEditorFromSelection();
                },

                resetEditor() {
                    if (this.editorSvgUrl) {
                        this.openEditorTab(this.editorSvgUrl);
                    }
                },

                saveEditorState() {
                    return false;
                },

                loadEditorStates() {
                    this.editorStates = [];
                },

                loadEditorStateById() {
                    return false;
                },

                deleteEditorStateById() {
                    return false;
                },

                importLogoToEditor(url) {
                    console.log('importLogoToEditor called with URL:', url);
                    console.log('editorSvgElement exists:', !!this.editorSvgElement);
                    
                    // Close import modal
                    this.showImportModal = false;
                    this.replaceTargetElement = null;
                    
                    // If no SVG is loaded yet, open editor tab with this SVG
                    if (!this.editorSvgElement) {
                        console.log('Opening editor tab with new SVG');
                        this.openEditorTab(url);
                    } else {
                        console.log('Adding vector to existing canvas');
                        // Add this vector to the existing canvas
                        this.addVectorToCanvas(url);
                    }
                },

                async addVectorToCanvas(url) {
                    console.log('addVectorToCanvas called with URL:', url);
                    try {
                        console.log('Fetching vector from URL...');
                        const response = await fetch(url);
                        const svgText = await response.text();
                        console.log('Vector fetched successfully, length:', svgText.length);
                        
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(svgText, 'image/svg+xml');
                        const importedSvg = doc.querySelector('svg');
                        console.log('Imported SVG element parsed:', !!importedSvg);
                        
                        if (!importedSvg) {
                            console.error('No SVG element found in imported document');
                            return;
                        }
                        
                        // First, extract and preserve style and defs elements at root level
                        const styles = Array.from(importedSvg.querySelectorAll('style'));
                        const defs = Array.from(importedSvg.querySelectorAll('defs'));
                        
                        // Move styles and defs to main SVG root (with unique IDs to avoid conflicts)
                        const timestamp = Date.now();
                        styles.forEach((style, idx) => {
                            const clonedStyle = style.cloneNode(true);
                            clonedStyle.setAttribute('id', `imported-style-${timestamp}-${idx}`);
                            this.editorSvgElement.appendChild(clonedStyle);
                        });
                        
                        defs.forEach((def, idx) => {
                            const clonedDef = def.cloneNode(true);
                            clonedDef.setAttribute('id', `imported-defs-${timestamp}-${idx}`);
                            this.editorSvgElement.appendChild(clonedDef);
                        });
                        
                        // Create a group element to hold all imported elements
                        const group = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                        const groupId = 'imported-' + timestamp;
                        group.setAttribute('id', groupId);
                        group.setAttribute('data-layer-name', 'Imported Vector');
                        
                        // Get viewBox for positioning
                        const viewBox = this.editorSvgElement.getAttribute('viewBox')?.split(' ') || [0, 0, 512, 512];
                        const canvasWidth = parseFloat(viewBox[2]);
                        const canvasHeight = parseFloat(viewBox[3]);
                        
                        // Get imported SVG dimensions
                        const importViewBox = importedSvg.getAttribute('viewBox')?.split(' ') || [0, 0, 100, 100];
                        const importWidth = parseFloat(importViewBox[2]);
                        const importHeight = parseFloat(importViewBox[3]);
                        
                        // Calculate scale to fit imported vector reasonably on canvas
                        const maxSize = Math.min(canvasWidth, canvasHeight) * 0.8; // 80% of canvas for large display
                        const scale = Math.min(maxSize / importWidth, maxSize / importHeight);
                        
                        // Position at center
                        const translateX = (canvasWidth - importWidth * scale) / 2;
                        const translateY = (canvasHeight - importHeight * scale) / 2;
                        
                        group.setAttribute('transform', `translate(${translateX}, ${translateY}) scale(${scale})`);
                        
                        // Remove white backgrounds before importing
                        this.removeWhiteBackgrounds(importedSvg, importViewBox);
                        
                        // Move all remaining children (excluding style/defs already moved) from imported SVG to group
                        while (importedSvg.firstChild) {
                            // Skip style and defs as we've already handled them
                            if (importedSvg.firstChild.tagName === 'style' || importedSvg.firstChild.tagName === 'defs') {
                                importedSvg.firstChild.remove();
                                continue;
                            }
                            group.appendChild(importedSvg.firstChild);
                        }
                        
                        // Add group to canvas (replace selected logo if requested)
                        const replaceTarget = this.replaceTargetElement;
                        if (replaceTarget && replaceTarget.parentNode) {
                            const targetTransform = replaceTarget.getAttribute('transform');
                            if (targetTransform) {
                                group.setAttribute('transform', targetTransform);
                            }
                            replaceTarget.parentNode.insertBefore(group, replaceTarget);
                            replaceTarget.remove();
                            this.replaceTargetElement = null;
                        } else {
                            this.editorSvgElement.appendChild(group);
                        }
                        console.log('Group added to canvas:', groupId);
                        
                        // Make the group draggable
                        this.makeGroupDraggable(group);
                        console.log('Group made draggable');
                        this.selectMoveModeGroup(group);
                        
                        // Switch to move mode and clear undo stack when importing
                        this.editMode = false;
                        this.undoStack = [];
                        
                        // Update element interactivity based on current mode
                        this.updateElementInteractivity();
                        this.updateLayers();
                        this.hideHoverMenu();
                        this.updateHoverMenuPosition();
                        console.log('Layers updated, import complete');
                        
                    } catch (error) {
                        console.error('Failed to add vector:', error);
                        alert('Failed to add vector to canvas');
                    }
                },

                removeWhiteBackgrounds(svgElement, viewBox) {
                    // Parse viewBox to get dimensions
                    const [vbX, vbY, vbWidth, vbHeight] = viewBox.map(v => parseFloat(v));
                    
                    // Helper to check if color is white/near-white
                    const isWhiteColor = (fill, style) => {
                        if (!fill && !style) return false;
                        
                        const fillLower = (fill || '').toLowerCase().trim();
                        const styleLower = (style || '').toLowerCase();
                        
                        // Check hex values
                        if (fillLower === 'white' || fillLower === '#fff' || fillLower === '#ffffff') return true;
                        
                        // Check RGB values (255, 255, 255) or very close to white
                        const rgbMatch = fillLower.match(/rgb\((\d+),?\s*(\d+),?\s*(\d+)\)/);
                        if (rgbMatch) {
                            const [_, r, g, b] = rgbMatch.map(v => parseInt(v));
                            // Consider it white if all values are >= 250
                            if (r >= 250 && g >= 250 && b >= 250) return true;
                        }
                        
                        // Check style attribute
                        if (styleLower.includes('fill:white') || 
                            styleLower.includes('fill:#fff') || 
                            styleLower.includes('fill: white') || 
                            styleLower.includes('fill: #fff') ||
                            styleLower.includes('fill:#ffffff') ||
                            styleLower.includes('fill: #ffffff')) {
                            return true;
                        }
                        
                        return false;
                    };
                    
                    // ONLY remove white rectangles that are CLEARLY full-page backgrounds
                    // Be very conservative to avoid removing design elements
                    const rects = svgElement.querySelectorAll('rect');
                    rects.forEach(rect => {
                        const x = parseFloat(rect.getAttribute('x') || 0);
                        const y = parseFloat(rect.getAttribute('y') || 0);
                        const width = parseFloat(rect.getAttribute('width') || 0);
                        const height = parseFloat(rect.getAttribute('height') || 0);
                        const fill = rect.getAttribute('fill');
                        const style = rect.getAttribute('style');
                        
                        // Only remove if it covers at least 98% of viewBox (very conservative)
                        const widthRatio = width / vbWidth;
                        const heightRatio = height / vbHeight;
                        const isFullPageBackground = (widthRatio >= 0.98 && heightRatio >= 0.98);
                        
                        // Must be positioned at or very near the origin
                        const isAtOrigin = (Math.abs(x - vbX) < 5 && Math.abs(y - vbY) < 5);
                        
                        // Must be the very first child element (z-index)
                        const parent = rect.parentElement;
                        const siblings = Array.from(parent.children);
                        const isFirstElement = siblings.indexOf(rect) === 0;
                        
                        // ALL conditions must be true
                        if (isWhiteColor(fill, style) && isFullPageBackground && isAtOrigin && isFirstElement) {
                            console.log('Removing full-page white background rect:', rect);
                            rect.remove();
                        }
                    });
                    
                    // Check paths for full-page white backgrounds
                    // Most paths are part of logo design (like letter interiors), but sometimes
                    // the background is rendered as a path instead of a rect
                    const paths = svgElement.querySelectorAll('path');
                    const parent = paths.length > 0 ? paths[0].parentElement : null;
                    if (parent) {
                        const siblings = Array.from(parent.children);
                        paths.forEach((path, index) => {
                            // Only check the very first path element
                            if (siblings.indexOf(path) !== 0) return;
                            
                            const fill = path.getAttribute('fill');
                            const style = path.getAttribute('style');
                            const d = path.getAttribute('d');
                            
                            if (!isWhiteColor(fill, style) || !d) return;
                            
                            // Check if the path describes a full-page rectangle
                            // Common patterns: "M 0 0 L 2048 0 L 2048 2048 L 0 2048 L 0 0 z"
                            // or "M 0,0 L width,0 L width,height L 0,height Z"
                            const rectPattern = /M\s*[\d.\-]+[\s,]+[\d.\-]+\s*L\s*[\d.\-]+[\s,]+[\d.\-]+\s*L\s*[\d.\-]+[\s,]+[\d.\-]+\s*L\s*[\d.\-]+[\s,]+[\d.\-]+\s*[LZ]/i;
                            
                            if (rectPattern.test(d)) {
                                // Parse the path to check if it covers the full viewBox
                                const coords = d.match(/[\d.\-]+/g);
                                if (coords && coords.length >= 8) {
                                    const x1 = parseFloat(coords[0]);
                                    const y1 = parseFloat(coords[1]);
                                    const x2 = parseFloat(coords[2]);
                                    const y2 = parseFloat(coords[3]);
                                    const x3 = parseFloat(coords[4]);
                                    const y3 = parseFloat(coords[5]);
                                    
                                    // Check if it's a rectangle from origin covering most of the viewBox
                                    const pathWidth = Math.max(x2, x3);
                                    const pathHeight = Math.max(y2, y3);
                                    const isAtOrigin = (Math.abs(x1 - vbX) < 5 && Math.abs(y1 - vbY) < 5);
                                    const coversFullPage = (pathWidth >= vbWidth * 0.98 && pathHeight >= vbHeight * 0.98);
                                    
                                    if (isAtOrigin && coversFullPage) {
                                        console.log('Removing full-page white background path:', path);
                                        path.remove();
                                    }
                                }
                            }
                        });
                    }
                    
                    // DO NOT remove polygons - they are also part of logo design
                    
                    // Only remove very large white circles that are clearly backgrounds
                    const circles = svgElement.querySelectorAll('circle, ellipse');
                    circles.forEach((circle, index) => {
                        const fill = circle.getAttribute('fill');
                        const style = circle.getAttribute('style');
                        
                        // Only check first element and only if it's VERY large
                        if (index === 0 && isWhiteColor(fill, style)) {
                            const r = parseFloat(circle.getAttribute('r') || circle.getAttribute('rx') || 0);
                            // Circle must be huge (90% of viewBox) to be considered a background
                            if (r > Math.min(vbWidth, vbHeight) * 0.9) {
                                console.log('Removing full-page white circle background:', circle);
                                circle.remove();
                            }
                        }
                    });
                    
                    // Remove any style or fill attributes set to white on the SVG itself
                    if (svgElement.style.backgroundColor) {
                        svgElement.style.backgroundColor = 'transparent';
                    }
                    if (svgElement.getAttribute('fill') === 'white' || 
                        svgElement.getAttribute('fill') === '#fff' ||
                        svgElement.getAttribute('fill') === '#ffffff') {
                        svgElement.removeAttribute('fill');
                    }
                },

                removeSvgBackground() {
                    if (!this.editorSvgElement) return;

                    const viewBoxValues = (this.editorSvgElement.getAttribute('viewBox') || '0 0 512 512')
                        .split(/\s+/)
                        .map((v) => parseFloat(v));
                    const vbX = Number.isFinite(viewBoxValues[0]) ? viewBoxValues[0] : 0;
                    const vbY = Number.isFinite(viewBoxValues[1]) ? viewBoxValues[1] : 0;
                    const vbWidth = Number.isFinite(viewBoxValues[2]) ? viewBoxValues[2] : 512;
                    const vbHeight = Number.isFinite(viewBoxValues[3]) ? viewBoxValues[3] : 512;

                    let removedCount = 0;

                    const parseNum = (value, fallback = 0) => {
                        const num = parseFloat(value);
                        return Number.isFinite(num) ? num : fallback;
                    };

                    const isInvisible = (el) => {
                        const opacity = parseNum(el.getAttribute('opacity'), 1);
                        const fillOpacity = parseNum(el.getAttribute('fill-opacity'), 1);
                        return opacity <= 0 || fillOpacity <= 0;
                    };

                    const removeIfCanvasSized = (el, x, y, width, height) => {
                        if (!el || isInvisible(el)) return false;
                        const widthRatio = width / vbWidth;
                        const heightRatio = height / vbHeight;
                        const nearOrigin = Math.abs(x - vbX) <= 8 && Math.abs(y - vbY) <= 8;
                        const fullPage = widthRatio >= 0.95 && heightRatio >= 0.95;
                        const hasFill = (el.getAttribute('fill') || '').toLowerCase() !== 'none';

                        if (nearOrigin && fullPage && hasFill) {
                            el.remove();
                            removedCount += 1;
                            return true;
                        }
                        return false;
                    };

                    const editorBg = this.editorSvgElement.querySelectorAll('[data-editor-bg="true"]');
                    editorBg.forEach((el) => {
                        el.remove();
                        removedCount += 1;
                    });

                    const rects = this.editorSvgElement.querySelectorAll('rect');
                    rects.forEach((rect) => {
                        const x = parseNum(rect.getAttribute('x'), vbX);
                        const y = parseNum(rect.getAttribute('y'), vbY);
                        const width = parseNum(rect.getAttribute('width'), 0);
                        const height = parseNum(rect.getAttribute('height'), 0);
                        removeIfCanvasSized(rect, x, y, width, height);
                    });

                    const circles = this.editorSvgElement.querySelectorAll('circle, ellipse');
                    circles.forEach((circle) => {
                        const cx = parseNum(circle.getAttribute('cx'), vbX + vbWidth / 2);
                        const cy = parseNum(circle.getAttribute('cy'), vbY + vbHeight / 2);
                        const rx = parseNum(circle.getAttribute('rx'), parseNum(circle.getAttribute('r'), 0));
                        const ry = parseNum(circle.getAttribute('ry'), parseNum(circle.getAttribute('r'), 0));
                        const x = cx - rx;
                        const y = cy - ry;
                        const width = rx * 2;
                        const height = ry * 2;
                        removeIfCanvasSized(circle, x, y, width, height);
                    });

                    // Check paths for full-page white backgrounds
                    const paths = this.editorSvgElement.querySelectorAll('path');
                    paths.forEach((path, index) => {
                        // Only check first few paths
                        if (index > 2) return;
                        
                        const fill = path.getAttribute('fill');
                        const d = path.getAttribute('d');
                        
                        if (!fill || !d) return;
                        
                        // Check if white
                        const fillLower = fill.toLowerCase().trim();
                        const isWhite = fillLower === 'white' || 
                                       fillLower === '#fff' || 
                                       fillLower === '#ffffff' ||
                                       fillLower.match(/rgb\(25[0-5],?\s*25[0-5],?\s*25[0-5]\)/);
                        
                        if (!isWhite) return;
                        
                        // Check if it's a full-page rectangle path
                        const rectPattern = /M\s*[\d.\-]+[\s,]+[\d.\-]+\s*L\s*[\d.\-]+[\s,]+[\d.\-]+\s*L\s*[\d.\-]+[\s,]+[\d.\-]+\s*L\s*[\d.\-]+[\s,]+[\d.\-]+\s*[LZ]/i;
                        if (rectPattern.test(d)) {
                            const coords = d.match(/[\d.\-]+/g);
                            if (coords && coords.length >= 8) {
                                const pathWidth = Math.max(parseFloat(coords[2]), parseFloat(coords[4]));
                                const pathHeight = Math.max(parseFloat(coords[3]), parseFloat(coords[5]));
                                const nearOrigin = Math.abs(parseFloat(coords[0]) - vbX) <= 8 && Math.abs(parseFloat(coords[1]) - vbY) <= 8;
                                const coversFullPage = (pathWidth >= vbWidth * 0.95 && pathHeight >= vbHeight * 0.95);
                                
                                if (nearOrigin && coversFullPage) {
                                    path.remove();
                                    removedCount += 1;
                                }
                            }
                        }
                    });

                    if (this.editorSvgElement.style.backgroundColor) {
                        this.editorSvgElement.style.backgroundColor = 'transparent';
                    }
                    if (this.editorSvgElement.getAttribute('fill') && this.editorSvgElement.getAttribute('fill') !== 'none') {
                        this.editorSvgElement.removeAttribute('fill');
                    }

                    this.updateLayers();
                    this.hideHoverMenu();
                    alert(removedCount > 0 ? `Removed ${removedCount} background element(s).` : 'No removable SVG background found.');
                },

                makeGroupDraggable(group) {
                    // Re-binding can happen after restore/undo/import. Tear down old handlers first.
                    if (typeof group.__destroyDraggable === 'function') {
                        group.__destroyDraggable();
                    }

                    let isDragging = false;
                    let isResizing = false;
                    let resizeHandle = null;
                    let startX, startY;
                    let currentTransform = { translateX: 0, translateY: 0, scale: 1 };
                    let resizeBox = null;
                    let isSelected = false;
                    
                    // Parse existing transform
                    const transform = group.getAttribute('transform');
                    if (transform) {
                        const parsed = this.parseGroupTransform(transform);
                        currentTransform.translateX = parsed.translateX;
                        currentTransform.translateY = parsed.translateY;
                        currentTransform.scale = parsed.scale;
                    }
                    
                    // Create resize handles
                    const createResizeBox = () => {
                        if (resizeBox) return resizeBox;
                        
                        const bbox = group.getBBox();
                        const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                        g.setAttribute('class', 'resize-box');
                        
                        // Apply the same transform as the group so the box follows it
                        g.setAttribute('transform', 
                            `translate(${currentTransform.translateX}, ${currentTransform.translateY}) scale(${currentTransform.scale})`
                        );
                        
                        // Border - no pointer events
                        const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                        rect.setAttribute('x', bbox.x - 5);
                        rect.setAttribute('y', bbox.y - 5);
                        rect.setAttribute('width', bbox.width + 10);
                        rect.setAttribute('height', bbox.height + 10);
                        rect.setAttribute('fill', 'none');
                        rect.setAttribute('stroke', '#3b82f6');
                        rect.setAttribute('stroke-width', '2');
                        rect.setAttribute('stroke-dasharray', '5,5');
                        rect.style.pointerEvents = 'none';
                        g.appendChild(rect);
                        
                        // Corner and edge handles
                        const handleSize = 12;
                        const handles = [
                            { x: bbox.x - 5, y: bbox.y - 5, cursor: 'nwse-resize', pos: 'nw' },
                            { x: bbox.x + bbox.width / 2 - 5, y: bbox.y - 5, cursor: 'ns-resize', pos: 'n' },
                            { x: bbox.x + bbox.width + 5, y: bbox.y - 5, cursor: 'nesw-resize', pos: 'ne' },
                            { x: bbox.x + bbox.width + 5, y: bbox.y + bbox.height / 2 - 5, cursor: 'ew-resize', pos: 'e' },
                            { x: bbox.x + bbox.width + 5, y: bbox.y + bbox.height + 5, cursor: 'nwse-resize', pos: 'se' },
                            { x: bbox.x + bbox.width / 2 - 5, y: bbox.y + bbox.height + 5, cursor: 'ns-resize', pos: 's' },
                            { x: bbox.x - 5, y: bbox.y + bbox.height + 5, cursor: 'nesw-resize', pos: 'sw' },
                            { x: bbox.x - 5, y: bbox.y + bbox.height / 2 - 5, cursor: 'ew-resize', pos: 'w' }
                        ];
                        
                        handles.forEach(h => {
                            const handle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                            handle.setAttribute('cx', h.x + handleSize / 2);
                            handle.setAttribute('cy', h.y + handleSize / 2);
                            handle.setAttribute('r', handleSize / 2);
                            handle.setAttribute('fill', '#3b82f6');
                            handle.setAttribute('stroke', 'white');
                            handle.setAttribute('stroke-width', '2');
                            handle.style.cursor = h.cursor;
                            handle.style.pointerEvents = 'all';
                            handle.dataset.position = h.pos;
                            
                            // Add event listeners directly to the handle
                            handle.addEventListener('mousedown', (e) => {
                                isResizing = true;
                                resizeHandle = h.pos;
                                const coords = getCoords(e);
                                startX = coords.x;
                                startY = coords.y;
                                e.stopPropagation();
                                e.preventDefault();
                            });
                            
                            g.appendChild(handle);
                        });
                        
                        group.parentNode.appendChild(g);
                        resizeBox = g;
                        return g;
                    };
                    
                    const updateResizeBox = () => {
                        if (!resizeBox || !isSelected) return;
                        
                        // Don't recreate during active resize/drag - just update on next frame
                        if (isResizing || isDragging) {
                            // Schedule recreation after the operation
                            return;
                        }
                        
                        resizeBox.remove();
                        resizeBox = null;
                        createResizeBox();
                        this.updateHoverMenuPosition();
                    };
                    
                    const showResizeBox = () => {
                        // Hide all other resize boxes first
                        const allResizeBoxes = this.editorSvgElement.querySelectorAll('.resize-box');
                        allResizeBoxes.forEach(box => box.remove());
                        
                        isSelected = true;
                        createResizeBox();
                        if (this.selectedElements.length === 1 && this.selectedElements[0] === group) {
                            this.updateHoverMenuPosition();
                        }
                    };
                    
                    const hideResizeBox = () => {
                        isSelected = false;
                        if (resizeBox) {
                            resizeBox.remove();
                            resizeBox = null;
                        }
                    };
                    
                    const getCoords = (e) => {
                        const svg = this.editorSvgElement;
                        const pt = svg.createSVGPoint();
                        pt.x = e.clientX || e.touches?.[0]?.clientX || 0;
                        pt.y = e.clientY || e.touches?.[0]?.clientY || 0;
                        return pt.matrixTransform(svg.getScreenCTM().inverse());
                    };
                    
                    const onStart = (e) => {
                        // Disable whole-vector dragging while in edit mode.
                        if (this.editMode) return;

                        // Only start drag if clicking within the group (includes all child elements)
                        const clickedElement = e.target;
                        
                        // Check if clicked element is within this group
                        let isWithinGroup = false;
                        if (group.contains(clickedElement)) {
                            isWithinGroup = true;
                        }
                        
                        if (!isWithinGroup) return;
                        
                        // If clicking on a resize handle, don't start dragging
                        if (clickedElement.closest('.resize-box')) return;
                        
                        // If in editGroupMode, don't interfere - let child elements handle their own dragging
                        if (this.editGroupMode && group === this.editingGroup) {
                            return;
                        }
                        
                        // Select this logo and show handles/menu
                        this.selectMoveModeGroup(group);
                        
                        isDragging = true;
                        group.style.cursor = 'grabbing';
                        
                        const coords = getCoords(e);
                        startX = coords.x;
                        startY = coords.y;
                        
                        e.stopPropagation();
                        e.preventDefault();
                    };
                    
                    const onMove = (e) => {
                        if (isResizing) {
                            const coords = getCoords(e);
                            const dx = coords.x - startX;
                            const dy = coords.y - startY;
                            
                            // Calculate scale change - more intuitive calculation
                            const distance = Math.sqrt(dx * dx + dy * dy);
                            const direction = (dx + dy) > 0 ? 1 : -1;
                            const scaleChange = (distance / 50) * direction * 0.1;
                            
                            currentTransform.scale = Math.max(0.1, Math.min(5, currentTransform.scale + scaleChange));
                            
                            requestAnimationFrame(() => {
                                const transformStr = `translate(${currentTransform.translateX}, ${currentTransform.translateY}) scale(${currentTransform.scale})`;
                                group.setAttribute('transform', transformStr);
                                if (resizeBox) {
                                    resizeBox.setAttribute('transform', transformStr);
                                }
                                if (this.selectedElements.length === 1 && this.selectedElements[0] === group) {
                                    this.updateHoverMenuPosition();
                                }
                            });
                            
                            startX = coords.x;
                            startY = coords.y;
                            e.preventDefault();
                            return;
                        }
                        
                        if (!isDragging) return;
                        
                        const coords = getCoords(e);
                        const dx = coords.x - startX;
                        const dy = coords.y - startY;
                        
                        currentTransform.translateX += dx;
                        currentTransform.translateY += dy;
                        
                        requestAnimationFrame(() => {
                            const transformStr = `translate(${currentTransform.translateX}, ${currentTransform.translateY}) scale(${currentTransform.scale})`;
                            group.setAttribute('transform', transformStr);
                            if (resizeBox) {
                                resizeBox.setAttribute('transform', transformStr);
                            }
                            if (this.selectedElements.length === 1 && this.selectedElements[0] === group) {
                                this.updateHoverMenuPosition();
                            }
                        });
                        
                        startX = coords.x;
                        startY = coords.y;
                        
                        e.preventDefault();
                    };
                    
                    const onEnd = () => {
                        const wasResizing = isResizing;
                        const wasDragging = isDragging;
                        
                        isDragging = false;
                        isResizing = false;
                        resizeHandle = null;
                        group.style.cursor = 'move';
                        
                        // Recreate resize box after operation completes
                        if ((wasResizing || wasDragging) && isSelected) {
                            updateResizeBox();
                            this.updateHoverMenuPosition();
                        }
                    };
                    
                    // Hide resize box when clicking outside
                    const onClickOutside = (e) => {
                        if (!isSelected) return;

                        // Keep selection active while interacting with the floating hover menu.
                        if (e.target.closest('[data-hover-menu="true"]')) return;

                        // Don't hide if clicking on a handle or the resize box itself
                        const isResizeBoxElement = e.target.closest('.resize-box');
                        if (isResizeBoxElement) return;
                        
                        // Hide if clicking outside the group
                        if (!group.contains(e.target)) {
                            if (!this.isSelecting) {
                                this.clearSelection();
                            } else {
                                hideResizeBox();
                            }
                        }
                    };
                    
                    group.__showResizeBox = showResizeBox;
                    group.__hideResizeBox = hideResizeBox;
                    group.__updateResizeBox = updateResizeBox;
                    group.style.cursor = 'move';
                    group.addEventListener('mousedown', onStart);
                    group.addEventListener('touchstart', onStart);
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('touchmove', onMove);
                    document.addEventListener('mouseup', onEnd);
                    document.addEventListener('touchend', onEnd);
                    document.addEventListener('mousedown', onClickOutside);

                    group.__destroyDraggable = () => {
                        hideResizeBox();
                        group.removeEventListener('mousedown', onStart);
                        group.removeEventListener('touchstart', onStart);
                        document.removeEventListener('mousemove', onMove);
                        document.removeEventListener('touchmove', onMove);
                        document.removeEventListener('mouseup', onEnd);
                        document.removeEventListener('touchend', onEnd);
                        document.removeEventListener('mousedown', onClickOutside);
                    };
                },

                async fetchUserLogos() {
                    this.loadingUserLogos = true;
                    try {
                        const response = await fetch('/domain-search/user-logos', {
                            method: 'GET',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            }
                        });

                        const data = await response.json();
                        if (data.success && data.logos) {
                            this.userLogos = data.logos;
                        }
                    } catch (error) {
                        console.error('Failed to fetch user logos:', error);
                    } finally {
                        this.loadingUserLogos = false;
                    }
                },

                async queueSimilarIdeasLookup() {
                    if (!this.logoDomain) return;

                    try {
                        await fetch('/domain-search/queue-similar-ideas', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            },
                            body: JSON.stringify({
                                domain: this.logoDomain,
                                prompt: this.logoPrompt
                            })
                        });

                        // Poll for results
                        setTimeout(() => this.fetchSimilarIdeas(), 3000);
                    } catch (err) {
                        console.error('Similar ideas queue error:', err);
                    }
                },

                async fetchSimilarIdeas() {
                    try {
                        const response = await fetch(`/domain-search/similar-ideas?domain=${encodeURIComponent(this.logoDomain)}`);
                        const data = await response.json();
                        
                        if (data.ideas && data.ideas.length > 0) {
                            this.similarIdeas = data.ideas;
                        } else {
                            // Keep polling if not ready
                            setTimeout(() => this.fetchSimilarIdeas(), 3000);
                        }
                    } catch (err) {
                        console.error('Fetch similar ideas error:', err);
                    }
                },

                loadFromSimilar(idea) {
                    this.logoDomain = idea.domain || this.logoDomain;
                    this.logoPrompt = idea.prompt || idea.query;
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            };
        }
    </script>
