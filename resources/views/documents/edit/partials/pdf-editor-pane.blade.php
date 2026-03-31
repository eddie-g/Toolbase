<div class="tab-content active" id="pdf-editor" @if(($activeTab ?? 'pdf-editor') !== 'pdf-editor') style="display:none;" @endif>

    <!-- Guided waiting overlay (hidden by default) -->
    <div id="guided-waiting-overlay" style="display:none; position:absolute; inset:0; z-index:50; background:#111827; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:40px;">
        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:#4dd0a8; margin-bottom:20px;">
            <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>
            <rect x="9" y="3" width="6" height="4" rx="1"/>
            <path d="M9 14l2 2 4-4"/>
        </svg>
        <h2 style="color:#e9f0ff; font-size:22px; font-weight:700; margin:0 0 8px;">Waiting on Guided completion</h2>
        <p style="color:#a9b7cf; font-size:15px; margin:0 0 24px; max-width:400px;">Fill out the invoice form on the <strong style="color:#4dd0a8;">Guided</strong> page, then click <strong style="color:#4dd0a8;">Generate PDF</strong> to build your document.</p>
        <a href="{{ route('documents.guided', $document) }}{{ request('style') ? '?style=' . request('style') : '' }}" style="background:#4dd0a8; color:#053322; font-weight:700; padding:12px 28px; border-radius:999px; border:none; cursor:pointer; font-size:15px; text-decoration:none; display:inline-block;">Go to Guided Page &rarr;</a>
    </div>

    <div class="flex flex-col lg:flex-row pdf-editor-layout">
        <!-- Mobile Sidebar Backdrop -->
        <div id="sidebar-backdrop" class="fixed inset-0 bg-black/50 z-30 lg:hidden hidden"></div>

        <!-- Sidebar - Mobile Drawer / Desktop Sidebar -->
        <aside id="sidebar" class="fixed lg:sticky inset-y-0 left-0 z-40 w-72 lg:w-64 bg-gray-800/95 border-r border-gray-700/50 transform -translate-x-full lg:translate-x-0 transition-transform duration-300 overflow-y-auto">
            <div class="p-4">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold flex items-center gap-2">
                        <span>Pages</span>
                        <button id="organize-pages-btn" class="p-1.5 hover:bg-gray-700/50 rounded-lg" title="Organize Pages">⚙️</button>
                    </h2>
                    <button id="sidebar-close" class="lg:hidden p-2 hover:bg-gray-700/50 rounded-lg">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <div class="space-y-3" id="page-list"></div>
                <div class="mt-4 text-sm text-gray-400" id="status"></div>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col min-w-0">
            <!-- Sticky Toolbar -->
            <div class="bg-gray-800/95 border-b border-gray-700/50 backdrop-blur-sm">
                <div class="mode-bar px-3 py-2 sticky z-[1400]" id="pdf-mode-bar">
                    <div class="flex items-center justify-between gap-2 flex-wrap">
                        <div class="flex items-center gap-2 flex-wrap">
                            <label id="mode-overlay-chip" class="mode-overlay-chip hidden inline-flex items-center gap-2 px-3 py-2 bg-gray-700/50 rounded-lg cursor-pointer hover:bg-gray-700 transition" aria-hidden="true">
                                <input type="checkbox" id="mode-overlay-toggle" class="w-4 h-4 rounded border-gray-600 text-emerald-500 focus:ring-emerald-500 focus:ring-offset-gray-800" disabled />
                                <span class="text-sm font-medium hidden sm:inline">Overlay Editor</span>
                                <span class="text-sm font-medium sm:hidden">Overlay</span>
                            </label>
                            <button
                                id="mode-edit-text"
                                type="button"
                                class="inline-flex items-center gap-2 px-3 py-2 bg-gray-700/50 hover:bg-gray-700 rounded-lg border border-gray-600 text-sm font-medium transition"
                                aria-pressed="false"
                                title="Toggle text editing mode"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 21h4l10.5-10.5a2.121 2.121 0 10-3-3L5 18v3z"></path>
                                </svg>
                                <span class="hidden sm:inline">Edit Text</span>
                                <span class="sm:hidden">Edit</span>
                            </button>
                            <button id="mode-text" type="button" class="px-3 py-2 bg-gray-700/50 hover:bg-gray-700 rounded-lg text-sm font-medium transition">
                                <span class="hidden sm:inline">Add Text</span>
                                <span class="sm:hidden">Text</span>
                            </button>
                            <button id="mode-sign" type="button" class="px-3 py-2 bg-gray-700/50 hover:bg-gray-700 rounded-lg text-sm font-medium transition">Sign</button>
                            <button id="mode-image" type="button" class="px-3 py-2 bg-gray-700/50 hover:bg-gray-700 rounded-lg text-sm font-medium transition">
                                <span class="hidden sm:inline">Import Image</span>
                                <span class="sm:hidden">Image</span>
                            </button>
                            <button id="mode-shape" type="button" class="px-3 py-2 bg-gray-700/50 hover:bg-gray-700 rounded-lg text-sm font-medium transition">
                                <span class="hidden sm:inline">Shapes</span>
                                <span class="sm:hidden">⬜</span>
                            </button>
                            <button id="mode-draw" type="button" class="px-3 py-2 bg-gray-700/50 hover:bg-gray-700 rounded-lg text-sm font-medium transition">
                                <span class="hidden sm:inline">Draw</span>
                                <span class="sm:hidden">✏️</span>
                            </button>
                            <button id="mode-field" type="button" class="px-3 py-2 bg-gray-700/50 hover:bg-gray-700 rounded-lg text-sm font-medium transition">
                                <span class="hidden sm:inline">Field</span>
                                <span class="sm:hidden">Field</span>
                            </button>
                            <button id="view-clean-pdf" type="button" class="px-4 py-2 bg-transparent border border-emerald-500/50 text-emerald-200 hover:bg-emerald-500/10 rounded-lg text-sm font-medium transition" title="Open the redacted base PDF used by the editor">
                                <span class="hidden sm:inline">View Redacted Base</span>
                                <span class="sm:hidden">Redacted</span>
                            </button>
                            @if($editorDebug)
                                <button id="view-original-pdf" type="button" class="px-4 py-2 bg-transparent border border-gray-600 hover:bg-gray-700/50 rounded-lg text-sm font-medium transition">
                                    <span class="hidden sm:inline">View Original PDF</span>
                                    <span class="sm:hidden">Original</span>
                                </button>
                                <button
                                    id="revert-original-pdf"
                                    type="button"
                                    class="px-4 py-2 rounded-lg text-sm font-medium transition border {{ $hasOriginalBackup ? 'bg-transparent border-amber-500/60 text-amber-200 hover:bg-amber-500/10' : 'bg-transparent border-gray-700 text-gray-500 cursor-not-allowed opacity-60' }}"
                                    {{ $hasOriginalBackup ? '' : 'disabled' }}
                                    title="{{ $hasOriginalBackup ? 'Restore the original uploaded PDF' : 'Original backup is not available for this document' }}"
                                >
                                    <span class="hidden sm:inline">Revert Original</span>
                                    <span class="sm:hidden">Revert</span>
                                </button>
                            @endif
                        </div>
                        <div class="flex gap-2 items-center">
                            <button id="clear-btn" class="px-4 py-2 bg-transparent border border-gray-600 hover:bg-gray-700/50 rounded-lg text-sm font-medium transition hidden sm:block" type="button">Clear All</button>
                            <button id="convert-btn" class="px-3 py-2 bg-transparent border border-gray-600 hover:bg-gray-700/50 rounded-lg text-sm transition flex items-center gap-1.5" type="button" title="Convert PDF to Images">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14"/><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/></svg>
                                <span class="hidden sm:inline">Convert</span>
                            </button>
                            <button id="split-btn" class="px-3 py-2 bg-transparent border border-gray-600 hover:bg-gray-700/50 rounded-lg text-sm transition flex items-center gap-1.5" type="button" title="Split PDF into pages">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="8" height="18" rx="1"/><rect x="14" y="3" width="8" height="18" rx="1"/><line x1="12" y1="6" x2="12" y2="18" stroke-dasharray="2 2"/></svg>
                                <span class="hidden sm:inline">Split</span>
                            </button>
                            <div style="position: relative;">
                                <button id="settings-gear-btn" class="px-2 py-2 bg-transparent border border-gray-600 hover:bg-gray-700/50 rounded-lg text-sm transition" type="button" title="Settings">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                                </button>
                                <div id="settings-popover" class="settings-popover" style="display: none;">
                                    <div style="padding: 16px; min-width: 280px;">
                                        <div style="font-weight: 600; font-size: 14px; margin-bottom: 14px; display: flex; align-items: center; gap: 6px; color: #e5e7eb;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                                            Settings
                                        </div>
                                        <!-- Gridlines -->
                                        <div style="margin-bottom: 14px;">
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                                <div>
                                                    <div style="font-weight: 600; font-size: 13px; color: #d1d5db;">Gridlines</div>
                                                    <div style="font-size: 11px; color: #6b7280; margin-top: 2px;">Alignment guides on pages</div>
                                                </div>
                                                <label style="position: relative; display: inline-block; width: 44px; height: 24px; cursor: pointer;">
                                                    <input type="checkbox" id="settings-gridlines-toggle" style="opacity: 0; width: 0; height: 0;">
                                                    <span class="grid-toggle-slider"></span>
                                                </label>
                                            </div>
                                            <div id="settings-gridlines-options" style="display: none;">
                                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                                    <span style="font-size: 12px; color: #9ca3af;">Spacing</span>
                                                    <span id="settings-gridlines-spacing-label" style="font-size: 12px; font-weight: 600; color: #e5e7eb; background: rgba(255,255,255,0.1); padding: 1px 6px; border-radius: 4px;">50px</span>
                                                </div>
                                                <input type="range" id="settings-gridlines-spacing" min="20" max="200" value="50" step="10" style="width: 100%; height: 5px; border-radius: 3px; outline: none; cursor: pointer; accent-color: #3b82f6; margin-bottom: 10px;">
                                                <div style="display: flex; gap: 10px;">
                                                    <div style="display: flex; align-items: center; gap: 6px; flex: 1;">
                                                        <span style="font-size: 12px; color: #9ca3af;">Color</span>
                                                        <div style="position: relative; width: 24px; height: 24px; border-radius: 6px; overflow: hidden; border: 2px solid rgba(255,255,255,0.15); flex-shrink: 0;">
                                                            <input type="color" id="settings-gridlines-color" value="#3b82f6" style="position: absolute; width: 200%; height: 200%; top: -50%; left: -50%; border: none; cursor: pointer;">
                                                        </div>
                                                    </div>
                                                    <div style="display: flex; align-items: center; gap: 6px; flex: 1;">
                                                        <span style="font-size: 12px; color: #9ca3af;">Opacity</span>
                                                        <input type="range" id="settings-gridlines-opacity" min="5" max="50" value="15" style="flex: 1; height: 4px; border-radius: 2px; outline: none; cursor: pointer; accent-color: #3b82f6;">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="save-split-group" id="save-action-group">
                                <button id="save-btn" class="save-split-primary px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-gray-900 font-semibold rounded-lg text-sm transition flex items-center gap-1.5" type="button">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                    <span class="hidden sm:inline">Save</span>
                                    <span class="sm:hidden">Save</span>
                                </button>
                                <button id="save-dropdown-btn" class="save-split-toggle" type="button" aria-haspopup="menu" aria-expanded="false" aria-controls="save-dropdown-menu" title="More save options">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                                </button>
                                <div id="save-dropdown-menu" class="save-split-menu hidden" role="menu" aria-orientation="vertical" aria-labelledby="save-dropdown-btn">
                                    <button id="save-as-btn" class="save-split-menu-item" type="button" role="menuitem">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M20 21H4"/></svg>
                                        <span class="save-split-menu-copy">
                                            <span class="save-split-menu-title">Save As</span>
                                            <span class="save-split-menu-description">Rename this annotation set before saving.</span>
                                        </span>
                                    </button>
                                </div>
                            </div>
                            <button id="download-pdf-btn" class="px-4 py-2 bg-indigo-500 hover:bg-indigo-600 text-white font-semibold rounded-lg text-sm transition flex items-center gap-1.5" type="button" title="Stamp annotations onto PDF and download">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                <span class="hidden sm:inline">Download PDF</span>
                                <span class="sm:hidden">Download</span>
                            </button>
                            <button id="load-saved-pdf-btn" class="px-4 py-2 bg-sky-100 hover:bg-sky-200 text-sky-900 font-semibold rounded-lg text-sm transition flex items-center gap-1.5 border border-sky-300" type="button" title="Load saved annotation state back into the editor as editable shapes">
                                <span class="hidden sm:inline">Load Annotations</span>
                                <span class="sm:hidden">Load</span>
                            </button>
                            @if($editorDebug)
                                <a id="saved-edit-preview-btn" href="{{ $savedEditPreviewUrl ?? route('documents.savedEdit', $document) }}" target="_blank" rel="noopener" class="px-4 py-2 bg-amber-100 hover:bg-amber-200 text-amber-900 font-semibold rounded-lg text-sm transition flex items-center gap-1.5 border border-amber-300">
                                    <span class="hidden sm:inline">Last Redaction</span>
                                    <span class="sm:hidden">Preview</span>
                                </a>
                            @endif
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 mt-2" style="display: none;">
                    </div>
                    <div class="selection-toolbar hidden px-3 py-2 sticky z-30 bg-gray-800/95 backdrop-blur-sm border-t border-gray-700/50" id="selection-toolbar">
                        <div class="toolbar-label" id="selection-label">Click a text block to edit</div>
                        <div class="toolbar-controls" id="selection-controls">
                            <div class="tb-group">
                                <select id="selected-font" disabled>
                                    <option value="Helvetica">Helvetica</option>
                                    <option value="Verdana">Verdana</option>
                                    <option value="TrebuchetMS">Trebuchet MS</option>
                                    <option value="TimesRoman">Times Roman</option>
                                    <option value="Georgia">Georgia</option>
                                    <option value="Palatino">Palatino</option>
                                    <option value="Garamond">Garamond</option>
                                    <option value="Courier">Courier</option>
                                </select>
                                <select id="selected-weight" disabled style="min-width:72px;">
                                    <option value="100">Thin</option>
                                    <option value="200">Extra Light</option>
                                    <option value="300">Light</option>
                                    <option value="400">Regular</option>
                                    <option value="500">Medium</option>
                                    <option value="600">Semi Bold</option>
                                    <option value="700">Bold</option>
                                    <option value="800">Extra Bold</option>
                                    <option value="900">Black</option>
                                </select>
                                <input type="number" id="selected-size" min="8" max="144" value="16" disabled style="width:52px;" />
                            </div>
                            <div class="tb-divider"></div>
                            <div class="tb-group">
                                <button type="button" id="selected-bold" class="icon-btn" title="Bold" aria-pressed="false" disabled><strong>B</strong></button>
                                <button type="button" id="selected-italic" class="icon-btn" title="Italic" aria-pressed="false" disabled><em>I</em></button>
                                <button type="button" id="selected-underline" class="icon-btn" title="Underline" aria-pressed="false" disabled><u>U</u></button>
                            </div>
                            <div class="tb-divider"></div>
                            <div class="tb-group">
                                <label class="color-btn" title="Text Color">
                                    <span class="color-swatch" id="selected-color-swatch"></span>
                                    <input type="color" id="selected-color" value="#000000" disabled />
                                </label>
                                <label class="color-btn" title="Background Color">
                                    <span class="color-swatch" id="selected-bg-swatch"></span>
                                    <input type="color" id="selected-bg" value="#ffffff" disabled />
                                </label>
                            </div>
                            <div class="tb-divider"></div>
                            <div class="tb-group">
                                <select id="selected-align" disabled style="min-width:64px;">
                                    <option value="left">Left</option>
                                    <option value="center">Center</option>
                                    <option value="right">Right</option>
                                </select>
                                <select id="selected-opacity" disabled style="min-width:56px;">
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
                            <div class="tb-divider"></div>
                            <button type="button" id="selected-merge" class="icon-btn" title="Merge selected overlay blocks" disabled>Merge</button>
                            <div class="tb-divider"></div>
                            <button type="button" id="selected-delete" class="danger-btn" title="Delete" disabled>🗑 Delete</button>
                        </div>
                    </div>
                </div>

                <div class="edit-text-banner inactive" id="edit-text-banner">
                    <!-- Font Family -->
                    <select class="etb-font-select" id="etb-font" title="Font Family">
                        <option value="Helvetica">Helvetica</option>
                        <option value="Verdana">Verdana</option>
                        <option value="TrebuchetMS">Trebuchet MS</option>
                        <option value="TimesRoman">Times Roman</option>
                        <option value="Georgia">Georgia</option>
                        <option value="Palatino">Palatino</option>
                        <option value="Garamond">Garamond</option>
                        <option value="Courier">Courier</option>
                    </select>
                    <div class="etb-divider"></div>
                    <!-- Font Size -->
                    <div class="etb-size-group" title="Font Size">
                        <span class="etb-label">Size</span>
                        <input type="range" class="etb-size-slider" id="etb-size" min="0" max="100" step="1" value="50" />
                        <span class="etb-size-value" id="etb-size-value">16px</span>
                    </div>
                    <div class="etb-divider"></div>
                    <!-- Text Color -->
                    <div class="etb-color-wrap" title="Text Color">
                        <input type="color" id="etb-text-color" value="#000000" />
                    </div>
                    <!-- Background Color -->
                    <div class="etb-color-wrap" title="Background Color" style="margin-left: 2px;">
                        <input type="color" id="etb-bg-color" value="#ffffff" />
                    </div>
                    <div class="etb-divider"></div>
                    <!-- Opacity -->
                    <div class="etb-opacity-group">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <select class="etb-opacity-select" id="etb-opacity" title="Opacity">
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
                    <div class="etb-divider"></div>
                    <!-- Bold -->
                    <button type="button" class="etb-btn" id="etb-bold" title="Bold" aria-pressed="false"><strong>B</strong></button>
                    <!-- Italic -->
                    <button type="button" class="etb-btn" id="etb-italic" title="Italic" aria-pressed="false"><em>I</em></button>
                    <!-- Underline -->
                    <button type="button" class="etb-btn" id="etb-underline" title="Underline" aria-pressed="false" style="text-decoration: underline;">U</button>
                    <div class="etb-divider"></div>
                    <!-- Alignment -->
                    <select id="etb-align" title="Text Alignment" style="width: 80px;">
                        <option value="left">Left</option>
                        <option value="center">Center</option>
                        <option value="right">Right</option>
                    </select>
                    <div class="etb-divider"></div>
                    <!-- Copy -->
                    <button type="button" class="etb-btn" id="etb-copy" title="Duplicate">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    </button>
                    <!-- Delete -->
                    <button type="button" class="etb-btn" id="etb-delete" title="Delete" style="color: #f87171;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    </button>
                </div>

                <div class="edit-text-banner draw-toolbar inactive hidden" id="draw-toolbar">
                    <span class="etb-label">Draw</span>
                    <div class="etb-color-wrap" title="Stroke Color">
                        <input type="color" id="draw-stroke-color" value="#111827" />
                    </div>
                    <div class="etb-divider"></div>
                    <div class="etb-opacity-group">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <select class="etb-opacity-select" id="draw-opacity" title="Stroke Opacity">
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
                    <div class="etb-divider"></div>
                    <div class="etb-size-group draw-width-group" title="Stroke Width">
                        <span class="etb-label">Width</span>
                        <input type="range" class="etb-size-slider" id="draw-stroke-width" min="2" max="48" step="1" value="12" />
                        <span class="etb-size-value" id="draw-stroke-width-value">12px</span>
                    </div>
                    <div class="etb-divider"></div>
                    <div class="draw-preview" aria-hidden="true">
                        <span class="draw-preview-dot" id="draw-stroke-preview-dot"></span>
                    </div>
                    <span class="etb-label draw-hint">Drag on the page to draw</span>
                    <div style="flex: 1 1 auto;"></div>
                    <button type="button" class="etb-btn" id="draw-exit" title="Exit Draw">✕</button>
                </div>

                <div class="viewer bg-gray-900 p-2 sm:p-4 overflow-auto flex-1" id="viewer" style="overflow-x: auto;"></div>
                <div class="viewer-footer" id="viewer-footer">
                    <button id="load-more-pages" type="button">Load more pages</button>
                    <span id="page-count">Showing 0 of 0 pages</span>
                </div>
                <div class="ocr-document-view" id="ocr-document-view" style="display: none;">
                    <div class="ocr-loading" id="ocr-loading">Loading extracted text...</div>
                    <div class="ocr-document" id="ocr-document"></div>
                </div>
            </main>
        </div>
    </div>
</div>
