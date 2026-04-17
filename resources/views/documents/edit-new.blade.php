<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $document->original_name }} — Edit New</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js"></script>
    <script>
        if (typeof pdfjsLib !== 'undefined') {
            pdfjsLib.GlobalWorkerOptions.workerSrc =
                'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';
        }
    </script>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; background: #f3f4f6; font-family: system-ui, sans-serif; }

        .top-bar {
            position: sticky; top: 0; z-index: 40;
            background: #fff; border-bottom: 1px solid #e5e7eb;
            padding: 10px 20px; display: flex; align-items: center; gap: 12px;
        }
        .top-bar h1 { margin: 0; font-size: 15px; font-weight: 600; color: #111827; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .top-bar a  { font-size: 13px; color: #6b7280; text-decoration: none; white-space: nowrap; }
        .top-bar a:hover { color: #111827; }
        .top-bar .save-status { font-size: 12px; color: #6b7280; white-space: nowrap; }
        .top-bar .save-btn {
            appearance: none;
            border: none;
            border-radius: 8px;
            background: #111827;
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            padding: 8px 14px;
            cursor: pointer;
            white-space: nowrap;
        }
        .top-bar .save-btn[disabled] {
            background: #9ca3af;
            cursor: not-allowed;
        }
        .top-bar .download-btn {
            appearance: none;
            border: none;
            border-radius: 8px;
            background: #2563eb;
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            padding: 8px 14px;
            cursor: pointer;
            white-space: nowrap;
        }
        .top-bar .download-btn[disabled] {
            background: #93c5fd;
            cursor: wait;
        }
        .top-bar .history-btn {
            appearance: none;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: #fff;
            color: #374151;
            font-size: 15px;
            line-height: 1;
            padding: 5px 9px;
            cursor: pointer;
            white-space: nowrap;
            user-select: none;
        }
        .top-bar .history-btn:hover:not([disabled]) { background: #f3f4f6; }
        .top-bar .history-btn[disabled] { color: #d1d5db; cursor: not-allowed; }
        .top-bar .edit-mode-toggle {
            appearance: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            border: 1px solid #d1d5db;
            border-radius: 999px;
            background: linear-gradient(180deg, #ffffff 0%, #f9fafb 100%);
            color: #374151;
            padding: 6px 14px 6px 10px;
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.08);
            cursor: pointer;
            transition: background .18s ease, color .18s ease, border-color .18s ease, box-shadow .18s ease, transform .18s ease;
            white-space: nowrap;
        }
        .top-bar .edit-mode-toggle:hover {
            border-color: #9ca3af;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.12);
            transform: translateY(-1px);
        }
        .top-bar .edit-mode-toggle:focus-visible {
            outline: 2px solid rgba(59, 130, 246, 0.35);
            outline-offset: 2px;
        }
        .top-bar .edit-mode-toggle.is-active {
            background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
            border-color: #111827;
            color: #f9fafb;
            box-shadow: 0 14px 28px rgba(17, 24, 39, 0.2);
        }
        .top-bar .edit-mode-toggle__icon {
            width: 24px;
            height: 24px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #eef2f7;
            color: #4b5563;
            transition: background .18s ease, color .18s ease;
            flex-shrink: 0;
        }
        .top-bar .edit-mode-toggle.is-active .edit-mode-toggle__icon {
            background: rgba(245, 158, 11, 0.16);
            color: #fbbf24;
        }
        .top-bar .edit-mode-toggle__label {
            font-size: 13px;
            font-weight: 600;
            letter-spacing: -0.01em;
        }
        .top-bar .add-text-btn {
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 500;
            line-height: 1.2;
        }
        .top-bar .add-text-btn.active { background: #dbeafe; color: #1d4ed8; border-color: #93c5fd; }

        #pages-wrap { padding: 88px 20px 24px; max-width: 1100px; margin: 0 auto; display: flex; flex-direction: column; gap: 24px; align-items: center; }

        .page-card { background: #fff; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,.08); overflow: visible; width: fit-content; max-width: none; }
        .page-label {
            padding: 8px 16px; border-bottom: 1px solid #f3f4f6;
            font-size: 12px; font-weight: 500; color: #6b7280;
            display: flex; align-items: center; justify-content: space-between;
            width: 100%;
        }
        .page-canvas-wrap { display: flex; justify-content: center; background: #e5e7eb; padding: 16px; width: 100%; }

        .page-content {
            position: relative;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(0,0,0,.15);
            line-height: 0;
        }
        .page-content img { display: block; width: 100%; height: 100%; object-fit: fill; }

        /* Overlay canvas — covers the page, receives pointer events for hit-testing */
        .overlay-canvas {
            position: absolute; top: 0; left: 0;
            width: 100%; height: 100%;
            z-index: 2;
        }

        .acro-layer {
            position: absolute; top: 0; left: 0;
            width: 100%; height: 100%;
            pointer-events: none;
            z-index: 3;
        }

        /* Rich-HTML display layer — non-active annotations with per-selection formatting
         * (ann._richHtml) are rendered as positioned DIVs so the canvas and editor use the
         * same DOM layout. The layer is pointer-events:none so clicks still hit the canvas
         * for selection. z-index sits above the overlay canvas but below the active editor. */
        .rich-html-layer {
            position: absolute; top: 0; left: 0;
            width: 100%; height: 100%;
            pointer-events: none;
            z-index: 4;
        }
        .rich-html-item {
            position: absolute;
            box-sizing: border-box;
            padding: 0; margin: 0;
            overflow: visible;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .edit-new-acro {
            position: absolute;
            box-sizing: border-box;
            border: 1.25px solid rgba(37, 99, 235, 0.45);
            background: rgba(219, 234, 254, 0.7);
            border-radius: 2px;
            overflow: hidden;
        }

        .edit-new-acro.is-choice {
            background: transparent;
        }

        .edit-new-acro.is-interactive {
            pointer-events: auto;
        }

        .edit-new-acro.is-interactive:hover {
            background: rgba(219, 234, 254, 0.9);
        }

        .edit-new-acro.is-interactive:focus-within {
            border-color: rgba(37, 99, 235, 0.9);
            background: rgba(255, 255, 255, 0.95);
            z-index: 1;
        }

        /* Click-target wrapper — covers the full field area */
        .edit-new-acro-label {
            position: absolute;
            inset: 0;
            overflow: hidden;
            cursor: text;
        }
        .edit-new-acro-label.is-multiline {
            overflow-y: auto;
        }
        /* The actual contenteditable inner element */
        .edit-new-acro-label > .acro-editable {
            display: block;
            width: 100%;
            box-sizing: border-box;
            padding: 0 4px;
            font-family: Arial, Helvetica, sans-serif;
            color: #0f172a;
            overflow: hidden;
            white-space: nowrap;
            outline: none;
            cursor: text;
            /* line-height set dynamically to field height so text is vertically centred */
        }
        .edit-new-acro-label.is-multiline > .acro-editable {
            white-space: pre-wrap;
            line-height: 1.2;
            overflow-y: auto;
            padding-top: 2px;
        }

        .edit-new-acro-mark {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #2563eb;
            font-weight: 700;
            cursor: pointer;
        }

        /* Active editor — transparent contenteditable placed exactly over the selected annotation.
         * Background is transparent so the clean PDF shows through; the canvas already draws a
         * dashed selection border around the block. No border/bg here keeps it invisible until
         * the user types. */
        .active-editor {
            display: none;
            position: absolute;
            padding: 0; margin: 0;
            border: none; outline: none;
            background: transparent;
            overflow: visible;
            white-space: pre-wrap;
            word-break: break-word;
            pointer-events: auto;
            user-select: text;
            cursor: text;
            caret-color: #2563eb;
            z-index: 5;
        }
        /* New text annotation creation state — mirrors .text-box-creator from the edit view */
        .active-editor[data-creating="1"] {
            background: rgba(110, 231, 183, 0.04) !important;
            border: 2px dashed rgba(110, 231, 183, 0.7) !important;
            border-radius: 4px !important;
            padding: 4px 8px !important;
            min-height: 36px !important;
            overflow: visible !important;
            box-sizing: border-box;
        }
        .active-editor[data-creating="1"]:focus {
            background: rgba(255, 255, 255, 0.95) !important;
            border-color: rgba(110, 231, 183, 0.9) !important;
        }
        .active-editor[data-creating="1"]:empty::before {
            content: 'Type text here...';
            color: #9ca3af;
            font-size: 13px;
            font-family: Arial, Helvetica, sans-serif;
            font-weight: normal;
            font-style: normal;
            pointer-events: none;
            display: block;
            line-height: 1.4;
        }

        .annotation-tbc-menu {
            display: none;
            position: absolute;
            align-items: center;
            gap: 2px;
            background: rgba(17, 24, 39, 0.95);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 8px;
            padding: 4px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.4);
            z-index: 6;
            white-space: nowrap;
            backdrop-filter: blur(8px);
            transform: translateX(-50%);
        }
        .annotation-tbc-menu.is-below {
            transform: translate(-50%, 8px);
        }
        .annotation-tbc-menu .tbc-menu-btn {
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: 1px solid transparent;
            color: #9ca3af;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.15s;
        }
        .annotation-tbc-menu .tbc-menu-btn:hover {
            background: rgba(255,255,255,0.1);
            color: #e5e7eb;
            border-color: rgba(255,255,255,0.12);
        }
        .annotation-tbc-menu .tbc-menu-btn.tbc-ok { color: #6ee7b7; }
        .annotation-tbc-menu .tbc-menu-btn.tbc-ok:hover {
            background: rgba(110, 231, 183, 0.15);
            border-color: rgba(110, 231, 183, 0.3);
        }
        .annotation-tbc-menu .tbc-menu-btn.tbc-delete { color: #f87171; }
        .annotation-tbc-menu .tbc-menu-btn.tbc-delete:hover {
            background: rgba(248, 113, 113, 0.15);
            border-color: rgba(248, 113, 113, 0.3);
        }
        .annotation-tbc-menu .tbc-menu-divider {
            width: 1px;
            height: 20px;
            background: rgba(255,255,255,0.12);
            margin: 0 2px;
        }

        .text-drag-selection {
            position: absolute;
            border: 2px dashed rgba(110, 231, 183, 0.9);
            background: rgba(110, 231, 183, 0.12);
            border-radius: 4px;
            pointer-events: none;
            z-index: 7;
        }

        .resize-handle {
            display: none;
            position: absolute;
            width: 12px;
            height: 12px;
            background: #ffffff;
            border: 2px solid #2563eb;
            border-radius: 9999px;
            padding: 0;
            z-index: 6;
        }
        .resize-handle[data-dir="nw"] { cursor: nwse-resize; }
        .resize-handle[data-dir="ne"] { cursor: nesw-resize; }
        .resize-handle[data-dir="sw"] { cursor: nesw-resize; }
        .resize-handle[data-dir="se"] { cursor: nwse-resize; }

        #error-banner { background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 12px 16px; font-size: 13px; color: #dc2626; display: none; }
        #save-toast { position: fixed; bottom: 20px; right: 20px; background: #1e293b; color: #fff; font-size: 13px; padding: 8px 16px; border-radius: 8px; opacity: 0; transition: opacity .2s; pointer-events: none; z-index: 100; }
        #save-toast.show { opacity: 1; }
        /* ── Floating tool bar ─────────────────────────────────────────────── */
        .floating-tool-bar {
            position: fixed;
            top: 68px;
            left: 50%;
            transform: translateX(-50%);
            display: inline-flex;
            align-items: center;
            gap: 2px;
            padding: 6px 8px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.10), 0 1px 4px rgba(0,0,0,0.06);
            z-index: 39;
            white-space: nowrap;
        }
        .ftb-group {
            display: inline-flex;
            align-items: center;
            gap: 1px;
        }
        .ftb-sep {
            width: 1px;
            height: 28px;
            background: #e5e7eb;
            margin: 0 6px;
            flex-shrink: 0;
        }
        .ftb-btn {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 3px;
            width: 54px;
            height: 50px;
            padding: 0;
            border: 1px solid transparent;
            border-radius: 8px;
            background: transparent;
            color: #4b5563;
            cursor: pointer;
            font-size: 10px;
            font-weight: 500;
            font-family: system-ui, sans-serif;
            line-height: 1;
            transition: background .12s, color .12s, border-color .12s;
            user-select: none;
            position: relative;
        }
        .ftb-btn svg { flex-shrink: 0; }
        .ftb-btn:hover { background: #f3f4f6; color: #111827; }
        .ftb-btn.is-active {
            background: #eff6ff;
            color: #1d4ed8;
            border-color: #bfdbfe;
        }
        .ftb-btn.is-active svg { stroke: #1d4ed8; }
        .ftb-btn.is-disabled { opacity: 0.42; cursor: default; }
        .ftb-btn.is-disabled:hover { background: transparent; color: #4b5563; }

        .floating-zoom-bar {
            position: fixed;
            left: 50%;
            bottom: 18px;
            transform: translateX(-50%);
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: rgba(32, 36, 45, 0.95);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 10px;
            color: #e5e7eb;
            z-index: 9999;
            box-shadow: 0 12px 30px rgba(0,0,0,0.35);
            font-size: 14px;
            backdrop-filter: blur(8px);
        }
        .floating-zoom-bar .divider {
            width: 1px;
            height: 20px;
            background: rgba(255,255,255,0.2);
        }
        .floating-zoom-bar button {
            border: none;
            background: rgba(255,255,255,0.08);
            color: #f3f4f6;
            width: 28px;
            height: 28px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .floating-zoom-bar button:hover {
            background: rgba(255,255,255,0.16);
        }
        .floating-zoom-bar input[type="number"] {
            width: 54px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            color: #f3f4f6;
            padding: 4px 6px;
            border-radius: 6px;
            text-align: center;
            font-size: 14px;
        }
        .floating-zoom-bar .zoom-label {
            min-width: 48px;
            text-align: center;
            font-weight: 600;
        }

        /* ── Text Format Bar ─────────────────────────────────────────────────── */
        .ann-format-bar {
            position: fixed;
            top: 134px;
            left: 50%;
            transform: translateX(-50%);
            display: none;
            align-items: center;
            gap: 6px;
            flex-wrap: nowrap;
            padding: 6px 10px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.10), 0 1px 4px rgba(0,0,0,0.06);
            z-index: 38;
            white-space: nowrap;
            font-size: 13px;
            color: #1a202c;
            overflow-x: auto;
            max-width: calc(100vw - 32px);
        }
        .ann-format-bar.is-visible { display: flex; }
        .afb-divider {
            width: 1px;
            height: 22px;
            background: #e5e7eb;
            margin: 0 2px;
            flex-shrink: 0;
        }
        .ann-format-bar select {
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            color: #1a202c;
            border-radius: 6px;
            padding: 4px 6px;
            font-size: 12px;
            height: 30px;
            outline: none;
            cursor: pointer;
            -webkit-appearance: none;
            appearance: none;
            padding-right: 20px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%239ca3af'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 6px center;
        }
        .ann-format-bar select:focus { border-color: #93c5fd; outline: none; }
        .afb-font-select { width: 140px; }
        .afb-size-group {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .afb-size-label {
            font-size: 11px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            flex-shrink: 0;
        }
        .afb-size-slider {
            width: 80px;
            height: 20px;
            padding: 0;
            border: none;
            background: transparent;
            accent-color: #3b82f6;
            cursor: ew-resize;
        }
        .afb-size-value {
            min-width: 36px;
            padding: 0 6px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            font-size: 12px;
            font-variant-numeric: tabular-nums;
            color: #1a202c;
        }
        .afb-color-wrap {
            position: relative;
            width: 30px;
            height: 30px;
            border-radius: 6px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
            flex-shrink: 0;
            cursor: pointer;
            background: #f3f4f6;
        }
        .afb-color-wrap input[type="color"] {
            position: absolute;
            width: 200%;
            height: 200%;
            top: -50%;
            left: -50%;
            border: none;
            cursor: pointer;
            padding: 0;
        }
        .afb-opacity-group {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .afb-btn {
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            color: #6b7280;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            transition: background 0.15s, color 0.15s, border-color 0.15s;
            flex-shrink: 0;
        }
        .afb-btn:hover { background: #e5e7eb; color: #111827; }
        .afb-btn.is-active,
        .afb-btn[aria-pressed="true"] {
            background: linear-gradient(180deg, #dbeafe 0%, #bfdbfe 100%);
            border-color: #3b82f6;
            color: #1d4ed8;
        }
        .afb-btn.is-danger { color: #ef4444; }
        .afb-btn.is-danger:hover { background: #fee2e2; border-color: #fca5a5; color: #dc2626; }
    </style>
</head>
<body>

<div class="top-bar">
    <h1>{{ $document->original_name }}</h1>
    <span id="save-status" class="save-status">Saved</span>
    <button id="undo-btn" type="button" class="history-btn" title="Undo (Ctrl+Z)" disabled>&#8592;</button>
    <button id="redo-btn" type="button" class="history-btn" title="Redo (Ctrl+Y)" disabled>&#8594;</button>
    <button id="edit-mode-toggle" type="button" class="edit-mode-toggle" aria-pressed="false" title="Turn edit mode on to show editable text boxes">
        <span class="edit-mode-toggle__icon" aria-hidden="true">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 20h9"/>
                <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>
            </svg>
        </span>
        <span id="edit-mode-toggle-label" class="edit-mode-toggle__label">Edit Mode OFF</span>
    </button>
    <button id="add-text-btn" type="button" class="history-btn add-text-btn" title="Add Text — click or drag on the page to place a new text block">Add Text</button>
    <button id="save-btn" type="button" class="save-btn">Save</button>
    <button id="download-pdf-btn" type="button" class="download-btn">Download PDF</button>
    <a href="{{ route('documents.edit', $document) }}">← Edit</a>
    <a href="{{ route('documents.index') }}">All documents</a>
</div>

<div id="pages-wrap">
    <div id="error-banner"></div>
</div>
<div id="save-toast">Saved</div>

<!-- Floating tool bar -->
<div class="floating-tool-bar" id="floating-tool-bar">
    <!-- Group 1: Selection tools -->
    <div class="ftb-group">
        <button type="button" class="ftb-btn ftb-edit-mode" id="ftb-edit-mode" title="Edit PDF — click annotations to edit text">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>
            </svg>
            <span>Edit PDF</span>
        </button>
        <button type="button" class="ftb-btn is-disabled" title="Coming soon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M15 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 3 14 8 20 8"/>
            </svg>
            <span>Fill &amp; Sign</span>
        </button>
    </div>
    <div class="ftb-sep"></div>
    <!-- Group 2: Annotation tools -->
    <div class="ftb-group">
        <button type="button" class="ftb-btn ftb-add-text" id="ftb-add-text" title="Add Text — click on the page to place a new text block">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/>
            </svg>
            <span>Text</span>
        </button>
        <button type="button" class="ftb-btn is-disabled" title="Coming soon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 20H7L3 16V4a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v9"/><path d="m6.5 17.5 10-10m-7 10 10-10"/>
            </svg>
            <span>Erase</span>
        </button>
        <button type="button" class="ftb-btn is-disabled" title="Coming soon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 20h9"/><path d="M3 12l9-9 4 4-9 9H3v-4z"/>
            </svg>
            <span>Highlight</span>
        </button>
        <button type="button" class="ftb-btn is-disabled" title="Coming soon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="9" x2="15" y2="15"/><line x1="15" y1="9" x2="9" y2="15"/>
            </svg>
            <span>Redact</span>
        </button>
    </div>
    <div class="ftb-sep"></div>
    <!-- Group 3: Insert -->
    <div class="ftb-group">
        <button type="button" class="ftb-btn is-disabled" title="Coming soon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>
            </svg>
            <span>Image</span>
        </button>
        <button type="button" class="ftb-btn is-disabled" title="Coming soon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/>
            </svg>
            <span>Draw</span>
        </button>
        <button type="button" class="ftb-btn is-disabled" title="Coming soon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
            </svg>
            <span>Arrow</span>
        </button>
        <button type="button" class="ftb-btn is-disabled" title="Coming soon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
            <span>Check</span>
        </button>
    </div>
</div>
<div class="floating-zoom-bar" id="floating-zoom-bar">
    <button type="button" id="page-prev" aria-label="Previous page">‹</button>
    <input id="page-jump" type="number" min="1" value="1">
    <span>/</span>
    <span id="page-total">1</span>
    <span class="divider"></span>
    <button type="button" id="zoom-out" aria-label="Zoom out">−</button>
    <button type="button" id="zoom-in" aria-label="Zoom in">+</button>
    <span class="zoom-label" id="zoom-label">130%</span>
    <span class="divider"></span>
    <button type="button" id="page-next" aria-label="Next page">›</button>
</div>

<!-- Text Format Bar — shown when an annotation is selected -->
<div class="ann-format-bar" id="ann-format-bar">
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
    </select>
    <div class="afb-divider"></div>
    <!-- Font Size -->
    <div class="afb-size-group" title="Font Size">
        <span class="afb-size-label">Size</span>
        <input type="range" class="afb-size-slider" id="afb-size" min="8" max="72" step="1" value="12" />
        <span class="afb-size-value" id="afb-size-value">12pt</span>
    </div>
    <div class="afb-divider"></div>
    <!-- Text Color -->
    <div class="afb-color-wrap" title="Text Color">
        <input type="color" id="afb-text-color" value="#000000" />
    </div>
    <!-- Background Color -->
    <div class="afb-color-wrap" title="Background Color" style="margin-left:2px;">
        <input type="color" id="afb-bg-color" value="#ffffff" />
    </div>
    <div class="afb-divider"></div>
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
    <div class="afb-divider"></div>
    <!-- Bold -->
    <button type="button" class="afb-btn" id="afb-bold" title="Bold" aria-pressed="false"><strong>B</strong></button>
    <!-- Italic -->
    <button type="button" class="afb-btn" id="afb-italic" title="Italic" aria-pressed="false"><em>I</em></button>
    <!-- Underline -->
    <button type="button" class="afb-btn" id="afb-underline" title="Underline" aria-pressed="false" style="text-decoration:underline;">U</button>
    <div class="afb-divider"></div>
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
    <div class="afb-divider"></div>
    <!-- Duplicate -->
    <button type="button" class="afb-btn" id="afb-copy" title="Duplicate annotation">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
    </button>
    <!-- Delete -->
    <button type="button" class="afb-btn is-danger" id="afb-delete" title="Delete annotation">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
    </button>
</div>

<script>
(function () {
    const CSRF        = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const INFO_URL    = '{{ route('pdfTests.documentInfo', $document) }}';
    const SAVE_URL    = '{{ route('documents.saveAnnotationState', $document) }}';
    const DELETE_URL  = '{{ route('documents.deleteAnnotations', $document) }}';
    const DOWNLOAD_URL = '{{ route('documents.downloadAnnotatedPdf', $document) }}';
    const DOC_ID      = '{{ $document->id }}';
    const wrap        = document.getElementById('pages-wrap');
    const errorBanner = document.getElementById('error-banner');
    const saveToast   = document.getElementById('save-toast');
    const saveButton  = document.getElementById('save-btn');
    const downloadPdfButton = document.getElementById('download-pdf-btn');
    const saveStatus  = document.getElementById('save-status');
    const undoButton  = document.getElementById('undo-btn');
    const redoButton  = document.getElementById('redo-btn');
    const editModeToggle = document.getElementById('edit-mode-toggle');
    const editModeToggleLabel = document.getElementById('edit-mode-toggle-label');
    const addTextBtn  = document.getElementById('add-text-btn');
    const ftbEditMode = document.getElementById('ftb-edit-mode');
    const ftbAddText  = document.getElementById('ftb-add-text');
    const zoomLabel   = document.getElementById('zoom-label');
    const annFormatBar  = document.getElementById('ann-format-bar');
    const afbFont       = document.getElementById('afb-font');
    const afbSize       = document.getElementById('afb-size');
    const afbSizeValue  = document.getElementById('afb-size-value');
    const afbTextColor  = document.getElementById('afb-text-color');
    const afbBgColor    = document.getElementById('afb-bg-color');
    const afbOpacity    = document.getElementById('afb-opacity');
    const afbBold       = document.getElementById('afb-bold');
    const afbItalic     = document.getElementById('afb-italic');
    const afbUnderline  = document.getElementById('afb-underline');
    const afbAlign      = document.getElementById('afb-align');
    const afbValign     = document.getElementById('afb-valign');
    const afbCopy       = document.getElementById('afb-copy');
    const afbDelete     = document.getElementById('afb-delete');
    const zoomOutBtn  = document.getElementById('zoom-out');
    const zoomInBtn   = document.getElementById('zoom-in');
    const pageJumpInput = document.getElementById('page-jump');
    const pageTotalLabel = document.getElementById('page-total');
    const pagePrevBtn = document.getElementById('page-prev');
    const pageNextBtn = document.getElementById('page-next');
    const INFO_URL_OBJ = new URL(INFO_URL, window.location.origin);

    // ── Session ID (persisted per-document so saves survive reload) ───────────
    const SESSION_KEY = `edit_new_session_${DOC_ID}`;
    const ZOOM_MIN_PERCENT = 50;
    const ZOOM_MAX_PERCENT = 400;
    const ZOOM_STEP_PERCENT = 30;
    const initialZoomPercent = window.innerWidth < 768 ? 100 : 130;
    function getSessionId() {
        let id = localStorage.getItem(SESSION_KEY);
        if (!id) {
            id = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
                const r = Math.random() * 16 | 0;
                return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
            });
            localStorage.setItem(SESSION_KEY, id);
        }
        return id;
    }
    INFO_URL_OBJ.searchParams.set('session_id', getSessionId());

    // ── Module-level state ─────────────────────────────────────────────────────
    let _pdfDoc       = null;
    let _acroPdfDoc   = null;
    const pageData    = {};   // pi → { wPts, hPts, scale, canvasWidth, canvasHeight, annotations }
    const editedTexts = {};   // uid → string
    let acroFormEntries = [];
    let acroFieldLookup = {};
    let acroWidgetsByPage = {};

    let activeState = { pi: null, uid: null };
    let hoverState  = { pi: null, uid: null };
    let dragState   = { active: false, pi: null, uid: null, offsetXPts: 0, offsetYPts: 0 };
    let resizeState = { active: false, pi: null, uid: null, handle: null, startPt: null, startBox: null, startFontSize: null };
    let textCreationState = { active: false, pi: null, pointerId: null, startX: 0, startY: 0, rect: null, previewEl: null, moved: false };
    let isDirty     = false;
    let isSaving    = false;
    let isDownloadingPdf = false;
    let editModeEnabled = false;
    let addTextMode = false;
    let currentZoomPercent = initialZoomPercent;

    let measureCanvas = null;
    let overlayEmbeddedFonts = null;
    const pendingDeletedAnnotationIds = new Set();
    const pendingDeletedPromotedSourceKeys = new Set();

    // ── Undo / Redo history ────────────────────────────────────────────────────
    const HISTORY_LIMIT = 100;
    const undoStack = [];  // snapshots before each action
    const redoStack = [];  // snapshots that were undone

    // Capture a deep-enough snapshot of all mutable editor state.
    function captureSnapshot() {
        const annsByPage = {};
        for (const [pi, data] of Object.entries(pageData)) {
            annsByPage[pi] = data.annotations.map(ann => ({ ...ann }));
        }
        return {
            annsByPage,
            editedTexts: { ...editedTexts },
            deletedIds: new Set(pendingDeletedAnnotationIds),
            deletedKeys: new Set(pendingDeletedPromotedSourceKeys),
        };
    }

    // Restore state from a snapshot.
    function applySnapshot(snap) {
        // Restore annotations
        for (const [pi, anns] of Object.entries(snap.annsByPage)) {
            const data = pageData[pi];
            if (data) data.annotations = anns.map(ann => ({ ...ann }));
        }
        // Restore edited texts
        for (const key of Object.keys(editedTexts)) delete editedTexts[key];
        Object.assign(editedTexts, snap.editedTexts);
        // Restore pending deletes
        pendingDeletedAnnotationIds.clear();
        for (const id of snap.deletedIds) pendingDeletedAnnotationIds.add(id);
        pendingDeletedPromotedSourceKeys.clear();
        for (const key of snap.deletedKeys) pendingDeletedPromotedSourceKeys.add(key);
    }

    // Push to undo stack before a user action. Call this BEFORE the mutation.
    function pushUndo() {
        undoStack.push(captureSnapshot());
        if (undoStack.length > HISTORY_LIMIT) undoStack.shift();
        redoStack.length = 0;  // any new action clears redo
        updateHistoryUi();
    }

    function updateHistoryUi() {
        if (undoButton) undoButton.disabled = undoStack.length === 0;
        if (redoButton) redoButton.disabled = redoStack.length === 0;
    }

    function getAvailablePageWidth() {
        return Math.max(wrap.clientWidth - 32, 200);
    }

    function getFitScaleForWidth(pageWidthPts) {
        return pageWidthPts > 0 ? (getAvailablePageWidth() / pageWidthPts) : 1;
    }

    function getAppliedScale(data) {
        if (!data) return 1;
        return (data.fitScale || 1) * (currentZoomPercent / 100);
    }

    function updatePageCardWidth(pi, canvasWidth) {
        const card = document.getElementById(`card-${pi + 1}`);
        if (!card) return;
        card.style.width = `${Math.ceil(Math.max(0, canvasWidth) + 32)}px`;
    }

    function applyPageScale(pi) {
        const data = pageData[pi];
        const pageEl = document.getElementById('pc-' + (pi + 1));
        if (!data || !pageEl) return;
        data.fitScale = getFitScaleForWidth(data.wPts);
        pageEl.style.setProperty('--scale', getAppliedScale(data));
    }

    function applyAllPageScales() {
        Object.keys(pageData).forEach((key) => applyPageScale(Number(key)));
    }

    function updateZoom(value) {
        const zoomValue = Math.max(ZOOM_MIN_PERCENT, Math.min(ZOOM_MAX_PERCENT, parseInt(value, 10)));
        currentZoomPercent = zoomValue;
        if (zoomLabel) zoomLabel.textContent = `${zoomValue}%`;
        applyAllPageScales();
    }

    function updatePageControls(totalPages = 1) {
        if (pageTotalLabel) pageTotalLabel.textContent = String(totalPages || 1);
        if (pageJumpInput) {
            pageJumpInput.max = String(totalPages || 1);
            const current = parseInt(pageJumpInput.value || '1', 10);
            if (!Number.isFinite(current) || current < 1 || current > (totalPages || 1)) {
                pageJumpInput.value = '1';
            }
        }
    }

    function scrollToPage(pageNumber) {
        const totalPages = _pdfDoc?.numPages || Object.keys(pageData).length || 1;
        const clamped = Math.max(1, Math.min(totalPages, pageNumber));
        if (pageJumpInput) pageJumpInput.value = String(clamped);
        const target = document.getElementById(`card-${clamped}`) || document.getElementById(`pc-${clamped}`);
        if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function syncCurrentPageFromScroll() {
        if (!pageJumpInput) return;
        const pages = Array.from(document.querySelectorAll('.page-card[data-page-number]'));
        if (!pages.length) return;
        const viewportBottom = window.innerHeight || document.documentElement.clientHeight || 0;
        let bestPage = null;
        let bestOverlap = -Infinity;
        pages.forEach((page) => {
            const rect = page.getBoundingClientRect();
            const overlapTop = Math.max(rect.top, 0);
            const overlapBottom = Math.min(rect.bottom, viewportBottom);
            const overlap = overlapBottom - overlapTop;
            if (overlap > bestOverlap) {
                bestOverlap = overlap;
                bestPage = page;
            }
        });
        if (!bestPage) return;
        const nextPage = parseInt(bestPage.dataset.pageNumber || '1', 10);
        if (Number.isFinite(nextPage) && String(nextPage) !== pageJumpInput.value) {
            pageJumpInput.value = String(nextPage);
        }
    }

    function performUndo() {
        if (undoStack.length === 0) return;
        redoStack.push(captureSnapshot());
        const snap = undoStack.pop();
        clearActiveAnnotation();
        applySnapshot(snap);
        for (const pi of Object.keys(pageData)) redrawOverlay(Number(pi));
        markDirty();
        updateHistoryUi();
    }

    function performRedo() {
        if (redoStack.length === 0) return;
        undoStack.push(captureSnapshot());
        const snap = redoStack.pop();
        clearActiveAnnotation();
        applySnapshot(snap);
        for (const pi of Object.keys(pageData)) redrawOverlay(Number(pi));
        markDirty();
        updateHistoryUi();
    }

    // ── Font helpers ───────────────────────────────────────────────────────────
    // NOTE: Use SINGLE quotes around multi-word family names. These values are interpolated
    // into HTML `style="…"` attributes; embedding double quotes there truncates the attribute
    // and causes the entire inline style (font-family, font-size, etc.) to be dropped, so the
    // contenteditable falls back to body defaults — which breaks edit-mode parity with the
    // canvas rendering and with the format-bar font-size slider.
    const FONT_MAP = {
        Arial:         'Arial, Helvetica, sans-serif',
        'Arial,Bold':  'Arial, Helvetica, sans-serif',
        ArialMT:       'Arial, Helvetica, sans-serif',
        'Arial-BoldMT':'Arial, Helvetica, sans-serif',
        Helvetica:     'Arial, Helvetica, sans-serif',
        FreeSans:      "'Liberation Sans', Arial, Helvetica, sans-serif",
        FreeSerif:     "'Liberation Serif', 'Times New Roman', Times, serif",
        FreeMono:      "'Liberation Mono', 'Courier New', Courier, monospace",
        Times:         "'Times New Roman', Times, serif",
        TimesRoman:    "'Times New Roman', Times, serif",
        'Times New Roman': "'Times New Roman', Times, serif",
        Verdana:       'Verdana, Geneva, sans-serif',
        Tahoma:        'Tahoma, Geneva, sans-serif',
        DejaVuSans:    "'DejaVu Sans', Arial, Helvetica, sans-serif",
        DejaVuSerif:   "'DejaVu Serif', 'Times New Roman', serif",
        Courier:       "'Courier New', Courier, monospace",
    };

    const normalizeFontName = (name) => {
        let s = String(name || '').replace(/^PDF_/i, '').trim();
        if (!s) return '';
        s = s.replace(/PSMT$/i, '').replace(/PS(-\w+MT)$/i, '$1').trim();
        if (s.includes('+')) { const p = s.split('+', 2); if (p[0].length === 6) s = p[1]; }
        return s;
    };

    const fontFileFormat = (fileExt) => {
        const ext = String(fileExt || 'ttf').toLowerCase();
        if (ext === 'woff2') return 'woff2';
        if (ext === 'woff') return 'woff';
        return (ext === 'otf' || ext === 'cff') ? 'opentype' : 'truetype';
    };

    const shouldBypassEmbeddedFont = (name, family = '') => {
        const rawName = normalizeFontName(name);
        const rawFamily = normalizeFontName(family);
        return /^Free(?:Sans|Serif|Mono)/i.test(rawName)
            || /^Free(?:Sans|Serif|Mono)/i.test(rawFamily)
            || /^ArialMT$/i.test(rawName);
    };

    const loadEmbeddedFontFaces = (embeddedFonts) => {
        overlayEmbeddedFonts = embeddedFonts && typeof embeddedFonts === 'object' ? embeddedFonts : null;

        const existing = document.getElementById('edit-new-embedded-fonts');
        if (existing) existing.remove();
        if (!overlayEmbeddedFonts) return;

        let css = '';
        for (const [fontKey, fontData] of Object.entries(overlayEmbeddedFonts)) {
            const cleanName = String(fontData?.clean_name || fontKey || '').trim();
            const family = String(fontData?.family || fontKey || '').trim();
            if (!cleanName) continue;
            if (shouldBypassEmbeddedFont(cleanName, family)) continue;

            let filePath = String(fontData?.file_path || '').trim();
            if (!filePath) continue;

            let fileExt = String(fontData?.file_ext || 'ttf').toLowerCase();
            if (fileExt === 'cff') {
                filePath = filePath.replace(/\.cff$/i, '.otf');
                fileExt = 'otf';
            }
            if (fileExt === 'cid') continue;

            const format = fontFileFormat(fileExt);
            const weight = String(fontData?.css_weight || '400');
            const fontStyle = String(fontData?.css_style || 'normal');
            const fontStretch = String(fontData?.css_stretch || 'normal');
            const exactFamily = `PDF_${cleanName}`;
            const familyAlias = family ? `PDF_${family}` : '';

            css += `@font-face { font-family: '${exactFamily}'; src: url('${filePath}') format('${format}'); font-weight: ${weight}; font-style: ${fontStyle};${fontStretch !== 'normal' ? ` font-stretch: ${fontStretch};` : ''} font-display: block; }\n`;
            if (familyAlias && familyAlias !== exactFamily) {
                css += `@font-face { font-family: '${familyAlias}'; src: url('${filePath}') format('${format}'); font-weight: ${weight}; font-style: ${fontStyle};${fontStretch !== 'normal' ? ` font-stretch: ${fontStretch};` : ''} font-display: block; }\n`;
            }
        }

        if (!css) return;

        const style = document.createElement('style');
        style.id = 'edit-new-embedded-fonts';
        style.textContent = css;
        document.head.appendChild(style);
    };

    const systemFallbackFontFamily = (name, family = '') => {
        const raw = normalizeFontName(name || family);
        if (!raw) return FONT_MAP['Helvetica'];
        const key = raw.replace(/['"]/g, '').trim();
        if (FONT_MAP[key]) return FONT_MAP[key];
        const simple = key.split(/[-_,]/)[0];
        if (FONT_MAP[simple]) return FONT_MAP[simple];
        // Use single-quotes so the value is safe to interpolate into style="…" HTML attributes
        // without truncating the attribute at the first embedded double quote.
        return `'${key}', ${FONT_MAP['Helvetica']}`;
    };

    const fallbackFontFamily = (name, family = '') => {
        const rawExact = normalizeFontName(name);
        const rawFamily = normalizeFontName(family);

        if (overlayEmbeddedFonts) {
            const entries = Object.entries(overlayEmbeddedFonts);
            if (rawExact) {
                for (const [fontKey, fontData] of entries) {
                    const cleanName = String(fontData?.clean_name || fontKey || '').trim();
                    const embeddedFamily = String(fontData?.family || fontKey || '').trim();
                    if (!cleanName || shouldBypassEmbeddedFont(cleanName, embeddedFamily)) continue;
                    if (normalizeFontName(cleanName).toLowerCase() === rawExact.toLowerCase()) {
                        const fallback = systemFallbackFontFamily('', embeddedFamily || cleanName);
                        return `'PDF_${cleanName}', ${fallback}`;
                    }
                }
            }

            if (rawFamily) {
                for (const [fontKey, fontData] of entries) {
                    const cleanName = String(fontData?.clean_name || fontKey || '').trim();
                    const embeddedFamily = String(fontData?.family || fontKey || '').trim();
                    if (!cleanName || !embeddedFamily || shouldBypassEmbeddedFont(cleanName, embeddedFamily)) continue;
                    if (normalizeFontName(embeddedFamily).toLowerCase() === rawFamily.toLowerCase()) {
                        const fallback = systemFallbackFontFamily('', embeddedFamily);
                        return `'PDF_${cleanName}', ${fallback}`;
                    }
                }
            }
        }

        return systemFallbackFontFamily(name, family);
    };

    const ensureMeasureCtx = () => {
        if (!(measureCanvas instanceof HTMLCanvasElement)) measureCanvas = document.createElement('canvas');
        return measureCanvas.getContext('2d');
    };

    const ctxFont = (style) =>
        `${style.fontStyle || 'normal'} ${style.fontWeight || '400'} ${Math.max(1, Number(style.fontSizePx) || 1)}px ${style.fontFamily}`;

    const measureTextWidth = (text, style) => {
        const ctx = ensureMeasureCtx();
        if (!ctx) return 0;
        ctx.font = ctxFont(style);
        return ctx.measureText(String(text || '')).width || 0;
    };

    // ── AcroForm helpers ──────────────────────────────────────────────────────
    function buildAcroFieldLookup(entries) {
        const lookup = {};
        (Array.isArray(entries) ? entries : []).forEach((entry) => {
            const keys = [
                String(entry?.key || '').trim(),
                String(entry?.fieldName || '').trim(),
            ].filter(Boolean);
            keys.forEach((key) => { lookup[key] = entry; });
        });
        return lookup;
    }

    function normalizeAcroRect(rectLike) {
        if (!Array.isArray(rectLike) || rectLike.length < 4) return null;
        const rect = rectLike.slice(0, 4).map((value) => Number(value));
        return rect.every((value) => Number.isFinite(value)) ? rect : null;
    }

    function normalizeAcroTextColor(colorLike) {
        if (typeof colorLike === 'string') {
            const value = colorLike.trim();
            if (!value) return null;
            if (/^#[0-9a-f]{6}$/i.test(value)) return value.toLowerCase();
            if (/^[0-9a-f]{6}$/i.test(value)) return `#${value.toLowerCase()}`;
            return null;
        }

        if (Array.isArray(colorLike) && colorLike.length > 0) {
            const values = colorLike.slice(0, 3).map((value) => Number(value));
            if (!values.every((value) => Number.isFinite(value))) return null;
            const rgb = values.map((value) => (
                value <= 1
                    ? Math.max(0, Math.min(255, Math.round(value * 255)))
                    : Math.max(0, Math.min(255, Math.round(value)))
            ));
            while (rgb.length < 3) rgb.push(rgb[0]);
            return `#${rgb.map((value) => value.toString(16).padStart(2, '0')).join('')}`;
        }

        return null;
    }

    function acroFieldKey(annotation) {
        return String(annotation?.fieldName || annotation?.id || annotation?.fullName || '').trim();
    }

    async function ensureAcroPdfLoaded(documentInfo) {
        if (_acroPdfDoc) return;
        // Prefer to reuse the already-loaded background PDF (_pdfDoc, which is the clean PDF).
        // The clean PDF preserves acroform widget annotations, so there is no need for a
        // separate HTTP request.  This also avoids Chromium aborting a PDF-type XHR when
        // headless mode's PDF-viewer handler intercepts the download.
        if (_pdfDoc) { _acroPdfDoc = _pdfDoc; return; }
        // Fallback: fetch the original file as an ArrayBuffer so browser PDF handling
        // does not interfere, then hand it to pdf.js directly.
        if (!documentInfo?.file_url) return;
        const resp = await fetch(documentInfo.file_url, { credentials: 'same-origin' }).catch(() => null);
        if (!resp?.ok) return;
        _acroPdfDoc = await pdfjsLib.getDocument({ data: await resp.arrayBuffer() }).promise;
    }

    function normalizeAcroWidget(annotation, pageNumber) {
        const fieldKey = acroFieldKey(annotation);
        const dbEntry = acroFieldLookup[fieldKey] || null;
        const rect = normalizeAcroRect(dbEntry?.rect || annotation?.rect);
        if (!rect) return null;

        const [x0, y0, x1, y1] = rect;
        const left = Math.min(x0, x1);
        const right = Math.max(x0, x1);
        const bottom = Math.min(y0, y1);
        const top = Math.max(y0, y1);
        const fieldType = String(dbEntry?.fieldType || annotation?.fieldType || '').trim().toUpperCase();
        const checkBox = Boolean(dbEntry?.checkBox ?? annotation?.checkBox);
        const radioButton = Boolean(dbEntry?.radioButton ?? annotation?.radioButton);

        // Ignore non-interactive push buttons such as hyperlink widgets. edit-new should
        // only expose fillable fields: text, choice, and checkbox/radio buttons.
        if (fieldType === 'BTN' && !checkBox && !radioButton) return null;
        if (fieldType === 'SIG') return null;

        return {
            key: fieldKey || `acro-${pageNumber}-${Math.random().toString(36).slice(2)}`,
            pageIndex: pageNumber - 1,
            fieldName: String(dbEntry?.fieldName || annotation?.fieldName || fieldKey || '').trim(),
            fieldType,
            value: dbEntry?.value ?? annotation?.fieldValue ?? '',
            exportValue: String(dbEntry?.exportValue || annotation?.exportValue || '').trim(),
            checkBox,
            radioButton,
            combo: Boolean(dbEntry?.combo ?? annotation?.combo),
            multiLine: Boolean(dbEntry?.multiLine ?? annotation?.multiLine),
            multiSelect: Boolean(dbEntry?.multiSelect ?? annotation?.multiSelect),
            readOnly: Boolean(annotation?.readOnly),
            textColor: normalizeAcroTextColor(
                dbEntry?.textColor
                ?? annotation?.textColor
                ?? annotation?.fontColor
                ?? annotation?.color
                ?? annotation?.defaultAppearanceData?.fontColor
            ) || '#0f172a',
            rect: [left, bottom, right, top],
        };
    }

    async function ensureAcroWidgetsForPage(pageNumber, documentInfo) {
        const pageKey = String(pageNumber);
        if (Array.isArray(acroWidgetsByPage[pageKey])) return acroWidgetsByPage[pageKey];
        if (!documentInfo || typeof pdfjsLib === 'undefined') return [];

        await ensureAcroPdfLoaded(documentInfo);
        if (!_acroPdfDoc) return [];

        const page = await _acroPdfDoc.getPage(pageNumber);
        const widgets = await page.getAnnotations({ intent: 'display' });
        const normalized = (Array.isArray(widgets) ? widgets : [])
            .filter((annotation) => annotation && annotation.subtype === 'Widget' && !annotation.hidden)
            .map((annotation) => normalizeAcroWidget(annotation, pageNumber))
            .filter(Boolean);

        acroWidgetsByPage = {
            ...acroWidgetsByPage,
            [pageKey]: normalized,
        };
        return normalized;
    }

    // Update or insert an entry in acroFormEntries / acroFieldLookup.
    function updateAcroEntry(widget, value) {
        widget.value = value;
        const key = widget.key;
        const existing = acroFieldLookup[key];
        if (existing) {
            existing.value = value;
        } else {
            const entry = {
                key,
                fieldName: widget.fieldName || key,
                value,
                pageIndex: widget.pageIndex ?? 0,
                fieldType: widget.fieldType || '',
                multiLine: widget.multiLine || false,
                checkBox: widget.checkBox || false,
                radioButton: widget.radioButton || false,
                exportValue: widget.exportValue || '',
                rect: widget.rect,
            };
            acroFormEntries.push(entry);
            acroFieldLookup[key] = entry;
            if (widget.fieldName && widget.fieldName !== key) acroFieldLookup[widget.fieldName] = entry;
        }
        markDirty();
    }

    function drawAcroOverlay(pi) {
        const data = pageData[pi];
        if (!data) return;
        const layer = document.getElementById('ac-' + (pi + 1));
        if (!(layer instanceof HTMLElement)) return;
        layer.innerHTML = '';

        const widgets = Array.isArray(data.acroWidgets) ? data.acroWidgets : [];
        widgets.forEach((widget) => {
            if (!Array.isArray(widget?.rect) || widget.rect.length < 4) return;
            const [leftPdf, bottomPdf, rightPdf, topPdf] = widget.rect;
            const cssLeft = leftPdf * data.scale;
            const cssTop = data.canvasHeight - (topPdf * data.scale);
            const cssWidth = Math.max(3, (rightPdf - leftPdf) * data.scale);
            const cssHeight = Math.max(3, (topPdf - bottomPdf) * data.scale);
            const fieldType = String(widget.fieldType || '').toUpperCase();
            const isChoice = fieldType === 'BTN' && (widget.checkBox || widget.radioButton);

            const el = document.createElement('div');
            el.className = 'edit-new-acro is-interactive' + (isChoice ? ' is-choice' : '');
            el.style.left   = `${cssLeft.toFixed(2)}px`;
            el.style.top    = `${cssTop.toFixed(2)}px`;
            el.style.width  = `${cssWidth.toFixed(2)}px`;
            el.style.height = `${cssHeight.toFixed(2)}px`;
            el.title = widget.fieldName || widget.key || '';

            if (isChoice) {
                const mark = document.createElement('div');
                mark.className = 'edit-new-acro-mark';
                mark.style.fontSize = `${Math.max(10, Math.min(cssHeight, cssWidth) * 0.7).toFixed(1)}px`;
                const isChecked = () => widget.radioButton
                    ? (String(widget.value || '') !== '' && String(widget.value || '') === String(widget.exportValue || ''))
                    : Boolean(widget.value);
                mark.textContent = isChecked() ? (widget.radioButton ? '●' : '✓') : '';
                mark.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (widget.readOnly) return;
                    let newVal;
                    if (widget.radioButton) {
                        newVal = isChecked() ? '' : String(widget.exportValue || widget.key);
                    } else {
                        newVal = !widget.value;
                    }
                    updateAcroEntry(widget, newVal);
                    mark.textContent = (widget.radioButton
                        ? (String(widget.value || '') !== '' && String(widget.value || '') === String(widget.exportValue || ''))
                        : Boolean(widget.value)) ? (widget.radioButton ? '●' : '✓') : '';
                });
                el.appendChild(mark);
            } else {
                // Text / combo / multiline field
                const isMultiLine = widget.multiLine || cssHeight > 22;
                // Flex centering wrapper (not contenteditable — avoids Chrome display:flex+contenteditable bug)
                const label = document.createElement('div');
                label.className = 'edit-new-acro-label' + (isMultiLine ? ' is-multiline' : '');
                // Inner editable element
                const inner = document.createElement('div');
                inner.className = 'acro-editable';
                inner.style.fontSize = '24px';
                inner.style.color = widget.textColor || '#0f172a';
                // For single-line fields: set line-height equal to field height so text is vertically centred
                if (!isMultiLine) inner.style.lineHeight = `${cssHeight}px`;
                if (!widget.readOnly) {
                    inner.contentEditable = 'true';
                    inner.spellcheck = false;
                    inner.addEventListener('pointerdown', (e) => e.stopPropagation());
                    inner.addEventListener('click', (e) => e.stopPropagation());
                    // Forward clicks on the label (which is full-height) to the inner editable
                    label.addEventListener('pointerdown', (e) => { e.stopPropagation(); });
                    label.addEventListener('click', (e) => { e.stopPropagation(); inner.focus(); });
                    inner.addEventListener('input', () => {
                        updateAcroEntry(widget, inner.innerText.replace(/\n$/, ''));
                    });
                    inner.addEventListener('keydown', (e) => {
                        // Prevent Escape from propagating to the canvas keydown handler
                        if (e.key === 'Escape') { e.stopPropagation(); inner.blur(); }
                        // Insert a <br> line break for Enter, avoiding browser's default <div> insertion
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            document.execCommand('insertLineBreak');
                        }
                    });
                }
                // Show saved value only — no placeholder text
                inner.textContent = String(widget.value ?? '').trim();
                label.appendChild(inner);
                el.appendChild(label);
            }

            layer.appendChild(el);
        });
    }

    // ── Annotation box resolution ──────────────────────────────────────────────
    function resolveAnnBox(ann) {
        const px = Number(ann.pdfX), py = Number(ann.pdfY);
        const pw = Number(ann.pdfWidth), ph = Number(ann.pdfHeight);
        if ([px, py, pw, ph].every(Number.isFinite) && pw > 0 && ph > 0) return { x: px, y: py, w: pw, h: ph };
        const sL = Number(ann.sourceBlockLeft), sT = Number(ann.sourceBlockTop);
        const sW = Number(ann.sourceBlockWidth), sH = Number(ann.sourceBlockHeight);
        const pageH = Number(ann.sourcePageHeight);
        if ([sL, sT, sW, sH, pageH].every(Number.isFinite) && sW > 0 && sH > 0)
            return { x: sL, y: pageH - (sT + sH), w: sW, h: sH };
        return null;
    }

    function resolveOriginalAnnBox(ann) {
        const box = ann._originalBox;
        if (box && [box.x, box.y, box.w, box.h].every(Number.isFinite)) return box;
        return resolveAnnBox(ann);
    }

    function annotationOffset(ann) {
        const cur = resolveAnnBox(ann), orig = resolveOriginalAnnBox(ann);
        if (!cur || !orig) return { dx: 0, dy: 0 };
        return { dx: cur.x - orig.x, dy: cur.y - orig.y };
    }

    function annotationSourceOffset(ann) {
        const cur = resolveAnnBox(ann), orig = resolveOriginalAnnBox(ann);
        if (!cur || !orig) return { dx: 0, dy: 0 };
        // dx: horizontal shift in CSS/source space (same in both coordinate systems)
        // dy: change in visual TOP in CSS top-down space.
        //     The visual top = pageH - (pdfY + pdfH) in PDF pts.
        //     When height changes (resize + move) the old formula -(cur.y - orig.y)
        //     diverges because it measures BOTTOM corner movement, not TOP.
        return { dx: cur.x - orig.x, dy: (orig.y + orig.h) - (cur.y + cur.h) };
    }

    function annotationDimensionsChanged(ann) {
        const cur = resolveAnnBox(ann);
        if (!cur) return false;
        // Use _originalPdfBox (captured from the loaded pdfWidth/pdfHeight) when available,
        // instead of _originalBox (which reflects the span-origin visual block and can be
        // a few pts smaller/larger than the stored pdfW/H — that false-positive would force
        // syncActiveEditor into the plain-text editor render, making selected/clicked mode
        // look different from the unselected canvas render).
        const origPdf = ann._originalPdfBox;
        const orig = (origPdf && [origPdf.x, origPdf.y, origPdf.w, origPdf.h].every(Number.isFinite))
            ? origPdf
            : resolveOriginalAnnBox(ann);
        if (!orig) return false;
        return Math.abs(cur.w - orig.w) > 0.25 || Math.abs(cur.h - orig.h) > 0.25;
    }

    function setAnnotationBox(ann, b) {
        ann.pdfX = b.x; ann.pdfY = b.y; ann.pdfWidth = b.w; ann.pdfHeight = b.h;
        if (ann.annotation_data && typeof ann.annotation_data === 'object') {
            ann.annotation_data.pdfX = b.x; ann.annotation_data.pdfY = b.y;
            ann.annotation_data.pdfWidth = b.w; ann.annotation_data.pdfHeight = b.h;
        }
    }

    function sourceVisualRectPx(ann, scale) {
        const sourceOffset = annotationSourceOffset(ann);
        const lines = renderableSourceLines(ann);
        if (lines.length) {
            const xs = lines.map((line) => Number(line.bbox?.[0]) + sourceOffset.dx).filter(Number.isFinite);
            const ys = lines.map((line) => Number(line.bbox?.[1]) + sourceOffset.dy).filter(Number.isFinite);
            const xe = lines.map((line) => Number(line.bbox?.[2]) + sourceOffset.dx).filter(Number.isFinite);
            const ye = lines.map((line) => Number(line.bbox?.[3]) + sourceOffset.dy).filter(Number.isFinite);
            if (xs.length && ys.length && xe.length && ye.length) {
                const left = Math.min(...xs) * scale;
                const top = Math.min(...ys) * scale;
                const right = Math.max(...xe) * scale;
                const bottom = Math.max(...ye) * scale;
                return {
                    left,
                    top,
                    width: Math.max(2, right - left),
                    height: Math.max(2, bottom - top),
                };
            }
        }

        const sL = Number(ann?.sourceBlockLeft);
        const sT = Number(ann?.sourceBlockTop);
        const sW = Number(ann?.sourceBlockWidth);
        const sH = Number(ann?.sourceBlockHeight);
        if ([sL, sT, sW, sH].every(Number.isFinite) && sW > 0 && sH > 0) {
            return {
                left: (sL + sourceOffset.dx) * scale,
                top: (sT + sourceOffset.dy) * scale,
                width: Math.max(2, sW * scale),
                height: Math.max(2, sH * scale),
            };
        }

        return null;
    }

    function sourceVisualOriginPts(ann) {
        const sourceOffset = annotationSourceOffset(ann);
        const lines = renderableSourceLines(ann);
        if (lines.length) {
            const xs = lines.map((line) => Number(line.bbox?.[0]) + sourceOffset.dx).filter(Number.isFinite);
            const ys = lines.map((line) => Number(line.bbox?.[1]) + sourceOffset.dy).filter(Number.isFinite);
            if (xs.length && ys.length) {
                return {
                    left: Math.min(...xs),
                    top: Math.min(...ys),
                };
            }
        }

        const sL = Number(ann?.sourceBlockLeft);
        const sT = Number(ann?.sourceBlockTop);
        if ([sL, sT].every(Number.isFinite)) {
            return {
                left: sL + sourceOffset.dx,
                top: sT + sourceOffset.dy,
            };
        }

        return null;
    }

    function annotationUsesStoredBoxForDisplay(ann) {
        const currentText = editedTexts[ann?._uid];
        const editedInSession = currentText !== undefined && currentText !== String(ann?.text ?? '');
        return editedInSession || annTextIsEdited(ann) || annotationDimensionsChanged(ann);
    }

    // Convert PDF-space box to canvas-pixel rect { left, top, width, height }
    function annRectPx(ann, scale, canvasHeight) {
        if (!annotationUsesStoredBoxForDisplay(ann)) {
            const sourceRect = sourceVisualRectPx(ann, scale);
            if (sourceRect) return sourceRect;
        }
        const box = resolveAnnBox(ann);
        if (!box) return null;
        return {
            left:   box.x * scale,
            top:    canvasHeight - (box.y + box.h) * scale,
            width:  box.w * scale,
            height: box.h * scale,
        };
    }

    // ── Style helpers (ported from pdfRecon2) ─────────────────────────────────
    function spanMatchesLineBBox(span, lineBBox) {
        const bbox = Array.isArray(span?.bbox) ? span.bbox : null;
        if (!bbox || bbox.length < 4 || !Array.isArray(lineBBox) || lineBBox.length < 4) return false;
        const spanLeft = Number(bbox[0]);
        const spanTop = Number(bbox[1]);
        const spanRight = Number(bbox[2]);
        const spanBottom = Number(bbox[3]);
        const lineLeft = Number(lineBBox[0]);
        const lineTop = Number(lineBBox[1]);
        const lineRight = Number(lineBBox[2]);
        const lineBottom = Number(lineBBox[3]);
        if (![spanLeft, spanTop, spanRight, spanBottom, lineLeft, lineTop, lineRight, lineBottom].every(Number.isFinite)) {
            return false;
        }

        const xi = Math.max(spanLeft, lineLeft - 0.5);
        const xa = Math.min(spanRight, lineRight + 0.5);
        if ((xa - xi) <= 0) return false;

        const spanCenterY = (spanTop + spanBottom) / 2;
        if (spanCenterY >= (lineTop - 0.5) && spanCenterY <= (lineBottom + 0.5)) {
            return true;
        }

        const yi = Math.max(spanTop, lineTop - 0.5);
        const ya = Math.min(spanBottom, lineBottom + 0.5);
        const overlapY = ya - yi;
        if (overlapY <= 0) return false;

        const spanHeight = Math.max(0.001, spanBottom - spanTop);
        const lineHeight = Math.max(0.001, lineBottom - lineTop);
        const overlapRatio = overlapY / Math.min(spanHeight, lineHeight);
        return overlapRatio >= 0.5;
    }

    function renderableSourceLines(ann) {
        if (Array.isArray(ann?._renderableSourceLines)) return ann._renderableSourceLines;

        const rawBBoxes = Array.isArray(ann?.sourceLineBBoxes) ? ann.sourceLineBBoxes : [];
        const rawTexts = Array.isArray(ann?.sourceTextLines) ? ann.sourceTextLines : [];
        const spans = Array.isArray(ann?.sourceSpans) ? ann.sourceSpans : [];

        let lines = rawBBoxes.map((bbox, rawIndex) => {
            const matchedSpans = spans.filter((span) => spanMatchesLineBBox(span, bbox));
            const width = Math.max(0, Number(bbox?.[2]) - Number(bbox?.[0]));
            const height = Math.max(0, Number(bbox?.[3]) - Number(bbox?.[1]));
            return {
                rawIndex,
                bbox,
                spans: matchedSpans,
                width,
                height,
                text: String(rawTexts[rawIndex] ?? ''),
            };
        });

        if (!lines.length && spans.length) {
            lines = spans.map((span, rawIndex) => ({
                rawIndex,
                bbox: Array.isArray(span?.bbox) ? span.bbox : null,
                spans: [span],
                width: Math.max(0, Number(span?.bbox?.[2]) - Number(span?.bbox?.[0])),
                height: Math.max(0, Number(span?.bbox?.[3]) - Number(span?.bbox?.[1])),
                text: String(span?.render_text ?? span?.text ?? ''),
            })).filter((line) => Array.isArray(line.bbox));
        }

        lines = lines.filter((line) => {
            const meaningfulText = String(line.text || '').trim().length > 0;
            const hasSpans = Array.isArray(line.spans) && line.spans.length > 0;
            const hasRealBox = line.width > 1 && line.height > 1;
            return hasSpans || (hasRealBox && meaningfulText);
        });

        if (rawTexts.length && lines.length === rawTexts.length) {
            lines.forEach((line, index) => {
                line.text = String(rawTexts[index] ?? '');
            });
        } else {
            lines.forEach((line) => {
                if (String(line.text || '').trim()) return;
                if (Array.isArray(line.spans) && line.spans.length) {
                    line.text = line.spans.map((span) => String(span?.render_text ?? span?.text ?? '')).join('');
                }
            });
        }

        ann._renderableSourceLines = lines;
        return lines;
    }

    function normalizeQuarterTurnDegrees(value) {
        const raw = Number(value);
        if (!Number.isFinite(raw)) return 0;
        const normalized = ((((raw % 360) + 540) % 360) - 180);
        if (Math.abs(normalized - 90) <= 12) return 90;
        if (Math.abs(normalized + 90) <= 12) return -90;
        return 0;
    }

    function sourceLineRotationDegrees(ann, lineIndex = 0) {
        const line = renderableSourceLines(ann)[lineIndex];
        const spans = Array.isArray(line?.spans) ? line.spans : [];
        let positiveWeight = 0;
        let negativeWeight = 0;

        spans.forEach((span) => {
            const rotation = normalizeQuarterTurnDegrees(span?.rotation);
            if (!rotation) return;
            const bbox = Array.isArray(span?.bbox) ? span.bbox : null;
            const spanWidth = bbox ? Math.abs(Number(bbox[2]) - Number(bbox[0])) : 0;
            const spanHeight = bbox ? Math.abs(Number(bbox[3]) - Number(bbox[1])) : 0;
            const weight = Math.max(
                1,
                spanWidth,
                spanHeight,
                String(span?.render_text ?? span?.text ?? '').replace(/\s+/g, '').length
            );
            if (rotation > 0) positiveWeight += weight;
            else negativeWeight += weight;
        });

        if (positiveWeight || negativeWeight) {
            return positiveWeight >= negativeWeight ? 90 : -90;
        }

        const bbox = Array.isArray(line?.bbox) ? line.bbox : null;
        if (bbox && bbox.length >= 4) {
            const width = Math.abs(Number(bbox[2]) - Number(bbox[0]));
            const height = Math.abs(Number(bbox[3]) - Number(bbox[1]));
            if (height > width * 1.2) {
                const direction = Array.isArray(spans[0]?.direction) ? spans[0].direction : null;
                const dy = Number(direction?.[1]);
                if (Number.isFinite(dy) && Math.abs(dy) > 0.75) {
                    return dy < 0 ? -90 : 90;
                }
            }
        }

        return 0;
    }

    function annotationSourceRotationDegrees(ann) {
        const rotations = renderableSourceLines(ann)
            .map((_, index) => sourceLineRotationDegrees(ann, index))
            .filter((value) => value !== 0);

        if (!rotations.length) return 0;

        const positiveCount = rotations.filter((value) => value > 0).length;
        const negativeCount = rotations.length - positiveCount;
        return positiveCount >= negativeCount ? 90 : -90;
    }

    function lineSpans(ann, lineIndex) {
        return Array.isArray(renderableSourceLines(ann)[lineIndex]?.spans)
            ? renderableSourceLines(ann)[lineIndex].spans
            : [];
    }

    function sourceStyle(ann, lineIndex = 0) {
        const spans = lineSpans(ann, lineIndex);
        const span = spans[0] || (Array.isArray(ann?.sourceSpans) ? ann.sourceSpans[0] : null) || null;
        const fontName = span?.embedded_font_name || span?.font || ann?.fontSourceName || ann?.fontFamily || '';
        const fontSizePt = Number(span?.font_size ?? span?.fontSize ?? ann?.fontSize) || 12;
        return {
            fontFamily: fallbackFontFamily(fontName, span?.embedded_font_family || ann?.fontFamily || ''),
            fontSizePt,
            fontWeight: String(span?.font_weight || span?.fontWeight || ann?.fontWeight || (span?.bold ? '700' : '400') || '400'),
            fontStyle:  span?.fontStyle || (span?.italic ? 'italic' : 'normal') || ann?.fontStyle || 'normal',
            fillStyle:  String(ann?.textColor || span?.hex_color || '#000000'),
        };
    }

    function compositeLineStyle(ann, lineIndex = 0) {
        const spans = lineSpans(ann, lineIndex);
        if (!spans.length) return sourceStyle(ann, lineIndex);

        const buckets = new Map();
        spans.forEach((span) => {
            const fontName = span?.embedded_font_name || span?.font || ann?.fontSourceName || ann?.fontFamily || '';
            const style = {
                fontFamily: fallbackFontFamily(fontName, span?.embedded_font_family || ann?.fontFamily || ''),
                fontSizePt: Number(span?.font_size ?? span?.fontSize ?? ann?.fontSize) || 12,
                fontWeight: String(span?.font_weight || span?.fontWeight || ann?.fontWeight || (span?.bold ? '700' : '400') || '400'),
                fontStyle: span?.fontStyle || (span?.italic ? 'italic' : 'normal') || ann?.fontStyle || 'normal',
                fillStyle: String(ann?.textColor || span?.hex_color || '#000000'),
            };
            const key = JSON.stringify(style);
            const spanText = String(span?.render_text ?? span?.text ?? '');
            const weight = Math.max(1, spanText.replace(/\s+/g, '').length || spanText.length || 1);
            buckets.set(key, (buckets.get(key) || 0) + weight);
        });

        const [bestKey] = Array.from(buckets.entries())
            .sort((left, right) => right[1] - left[1])[0] || [];

        return bestKey ? JSON.parse(bestKey) : sourceStyle(ann, lineIndex);
    }

    function editableLineStyle(ann, lineIndex = 0) {
        const baseStyle = compositeLineStyle(ann, lineIndex);
        const annFontFamily = String(ann?.fontFamily || '').trim();
        const annFontSize = Number(ann?.fontSize);
        const annFontWeight = String(ann?.fontWeight || '').trim();
        const annFontStyle = String(ann?.fontStyle || '').trim();
        const annTextColor = String(ann?.textColor || '').trim();

        return {
            fontFamily: annFontFamily ? fallbackFontFamily(annFontFamily, annFontFamily) : baseStyle.fontFamily,
            fontSizePt: annFontSize > 0 ? annFontSize : baseStyle.fontSizePt,
            fontWeight: annFontWeight || baseStyle.fontWeight,
            fontStyle: annFontStyle || baseStyle.fontStyle,
            fillStyle: annTextColor || baseStyle.fillStyle,
        };
    }

    function blockLineHeightPx(ann, lineIndex = 0, scale, styleOverride = null) {
        const lineBBox = Array.isArray(renderableSourceLines(ann)[lineIndex]?.bbox) ? renderableSourceLines(ann)[lineIndex].bbox : null;
        const rotation = sourceLineRotationDegrees(ann, lineIndex);
        const sourceH = lineBBox
            ? Math.max(
                0,
                Math.abs(rotation) === 90
                    ? (Number(lineBBox[2]) - Number(lineBBox[0]))
                    : (Number(lineBBox[3]) - Number(lineBBox[1]))
            ) * scale
            : 0;
        const resolvedStyle = styleOverride || sourceStyle(ann, lineIndex);
        const fontH = resolvedStyle.fontSizePt * scale * 1.18;
        const minHeightPx = lineBBox ? 0 : 12;
        return Math.max(minHeightPx, sourceH || 0, fontH);
    }

    function editorLineTopShiftPx(ann, lineIndex = 0, scale, styleOverride = null) {
        const style = styleOverride || compositeLineStyle(ann, lineIndex);
        const lineHeightPx = blockLineHeightPx(ann, lineIndex, scale, style);
        const fontSizePx = style.fontSizePt * scale;
        return Math.max(0, (lineHeightPx - fontSizePx) / 2);
    }

    function lineTargetWidthPx(ann, lineIndex, scale) {
        const rect = sourceLineRectWithinAnnotation(ann, lineIndex, scale);
        if (rect) {
            const rotation = sourceLineRotationDegrees(ann, lineIndex);
            const targetExtent = Math.abs(rotation) === 90 ? rect.height : rect.width;
            if (Number.isFinite(targetExtent) && targetExtent > 0) return targetExtent;
        }
        const lineBBox = Array.isArray(ann?.sourceLineBBoxes?.[lineIndex]) ? ann.sourceLineBBoxes[lineIndex] : null;
        if (lineBBox) {
            const rotation = sourceLineRotationDegrees(ann, lineIndex);
            return Math.max(
                2,
                Math.abs(rotation) === 90
                    ? Math.abs(Number(lineBBox[3]) - Number(lineBBox[1])) * scale
                    : Math.abs(Number(lineBBox[2]) - Number(lineBBox[0])) * scale
            );
        }
        return Math.max(2, (resolveAnnBox(ann)?.w || 2) * scale);
    }

    function measuredLineContentWidthPx(ann, lineIndex, scale, spans = null) {
        const resolvedSpans = Array.isArray(spans) ? spans : lineSpans(ann, lineIndex);
        if (resolvedSpans.length) {
            let totalPx = 0;
            let cursorPts = null;
            resolvedSpans.forEach((span) => {
                const drawText = String(span?.render_text ?? span?.text ?? '');
                if (!drawText) return;

                const style = editorSpanStyle(ann, span, scale);
                const bbox = Array.isArray(span?.bbox) ? span.bbox : null;
                const origin = Array.isArray(span?.origin) ? span.origin : null;
                const spanStartPts = bbox ? Number(bbox[0]) : (origin ? Number(origin[0]) : cursorPts);

                if (cursorPts !== null && Number.isFinite(spanStartPts)) {
                    totalPx += Math.max(0, (spanStartPts - cursorPts) * scale);
                }

                totalPx += measureTextWidth(drawText, {
                    fontFamily: style.fontFamily,
                    fontSizePx: style.fontSizePx,
                    fontWeight: style.fontWeight,
                    fontStyle: style.fontStyle,
                });

                if (bbox) {
                    cursorPts = Number(bbox[2]);
                } else if (Number.isFinite(spanStartPts)) {
                    cursorPts = spanStartPts;
                } else {
                    cursorPts = null;
                }
            });

            if (totalPx > 0) return totalPx;
        }

        const text = String(renderableSourceLines(ann)[lineIndex]?.text ?? '');
        const style = compositeLineStyle(ann, lineIndex);
        return measureTextWidth(text, {
            fontFamily: style.fontFamily,
            fontSizePx: style.fontSizePt * scale,
            fontWeight: style.fontWeight,
            fontStyle: style.fontStyle,
        });
    }

    function editorLineScaleX(ann, lineIndex, scale, spans = null) {
        const targetWidthPx = lineTargetWidthPx(ann, lineIndex, scale);
        const measuredWidthPx = measuredLineContentWidthPx(ann, lineIndex, scale, spans);
        if (!Number.isFinite(targetWidthPx) || !Number.isFinite(measuredWidthPx) || targetWidthPx <= 0 || measuredWidthPx <= 0) {
            return 1;
        }
        const rawRatio = targetWidthPx / measuredWidthPx;
        if (!Number.isFinite(rawRatio) || rawRatio <= 0) return 1;
        const clampedRatio = Math.max(0.5, Math.min(1.3, rawRatio));
        return Math.abs(clampedRatio - 1) <= 0.015 ? 1 : clampedRatio;
    }

    function fitActiveEditorSourceLines(editor, ann, scale) {
        if (!(editor instanceof HTMLElement) || !ann) return;
        const lineEls = Array.from(editor.children || []);
        lineEls.forEach((lineEl, index) => {
            if (!(lineEl instanceof HTMLElement)) return;
            const lineIndex = Number(lineEl.dataset.lineIndex ?? index);
            const targetWidthPx = lineTargetWidthPx(ann, lineIndex, scale);
            const contentEl = lineEl.querySelector('[data-line-content="1"]') || lineEl;
            if (!(contentEl instanceof HTMLElement)) return;
            contentEl.style.transformOrigin = 'top left';
            contentEl.style.transform = 'scaleX(1)';

            const rawWidthPx = Math.max(
                contentEl.scrollWidth || 0,
                contentEl.getBoundingClientRect().width || 0
            );
            if (!Number.isFinite(targetWidthPx) || targetWidthPx <= 0 || !Number.isFinite(rawWidthPx) || rawWidthPx <= 0) {
                return;
            }

            const rawRatio = targetWidthPx / rawWidthPx;
            const clampedRatio = Math.max(0.5, Math.min(1.3, rawRatio));
            const scaleX = Math.abs(clampedRatio - 1) <= 0.015 ? 1 : clampedRatio;
            contentEl.style.transform = `scaleX(${scaleX.toFixed(4)})`;
        });
    }

    // ── Drawing (ported from pdfRecon2) ───────────────────────────────────────
    function drawOriginalSource(ann, ctx, scale) {
        const spans = Array.isArray(ann?.sourceSpans) ? ann.sourceSpans : [];
        if (!spans.length) return false;
        const offset = annotationSourceOffset(ann);
        spans.forEach((span) => {
            const origin = Array.isArray(span?.origin) ? span.origin : null;
            if (!origin || origin.length < 2) return;
            const drawText = String(span?.render_text ?? span?.text ?? '');
            if (!drawText) return;
            const annFontFamilyOverride = String(ann?.fontFamily || '').trim();
            const fontFamily = annFontFamilyOverride
                ? fallbackFontFamily(annFontFamilyOverride, annFontFamilyOverride)
                : fallbackFontFamily(span?.embedded_font_name || span?.font, span?.embedded_font_family || span?.fontFamily || '');
            const fontSizePx = (Number(span?.font_size ?? span?.fontSize) || Number(ann?.fontSize) || 12) * scale;
            // Apply ann-level font overrides (from format bar) at render time;
            // span-derived values are used for measurement/layout via compositeLineStyle.
            const spanFontStyle  = span?.fontStyle || (span?.italic ? 'italic' : 'normal');
            const spanFontWeight = String(span?.font_weight || span?.fontWeight || (span?.bold ? '700' : '400') || '400');
            const fontStyle  = ann?.fontStyle  || spanFontStyle;
            const fontWeight = ann?.fontWeight || spanFontWeight;
            ctx.font         = ctxFont({ fontFamily, fontSizePx, fontWeight, fontStyle });
            ctx.fillStyle    = String(ann?.textColor || span?.hex_color || '#000000');
            ctx.textBaseline = 'alphabetic';
            const drawX = (Number(origin[0]) + offset.dx) * scale;
            const drawY = (Number(origin[1]) + offset.dy) * scale;
            const spanRotation = Number(span?.rotation ?? 0);
            const isVertical = Math.abs(Math.abs(spanRotation) - 90) < 1;
            const targetWidthPx = (() => {
                const bbox = Array.isArray(span?.bbox) ? span.bbox : null;
                if (!bbox || bbox.length < 4) return 0;
                // For 90°-rotated text the text runs along the y-axis; use bbox height as the length
                if (isVertical) return Math.max(0, (Number(bbox[3]) - Number(bbox[1])) * scale);
                return Math.max(0, (Number(bbox[2]) - Number(bbox[0])) * scale);
            })();
            const measuredWidthPx = ctx.measureText(drawText).width || 0;
            const rawRatio = (targetWidthPx > 0 && measuredWidthPx > 0) ? (targetWidthPx / measuredWidthPx) : 1;
            const scaleX = (!Number.isFinite(rawRatio) || rawRatio <= 0) ? 1 : Math.max(0.5, Math.min(1.3, rawRatio));
            if (isVertical) {
                // spanRotation -90: direction [0,-1] (upward) → canvas rotate(-π/2) draws text upward
                // spanRotation +90: direction [0,+1] (downward) → canvas rotate(+π/2) draws text downward
                const canvasAngle = spanRotation < 0 ? -Math.PI / 2 : Math.PI / 2;
                ctx.save();
                ctx.translate(drawX, drawY);
                ctx.rotate(canvasAngle);
                if (Math.abs(scaleX - 1) > 0.015) ctx.scale(scaleX, 1);
                ctx.fillText(drawText, 0, 0);
                ctx.restore();
                return;
            }
            if (Math.abs(scaleX - 1) > 0.015) {
                ctx.save();
                ctx.translate(drawX, drawY);
                ctx.scale(scaleX, 1);
                ctx.fillText(drawText, 0, 0);
                ctx.restore();
                return;
            }
            ctx.fillText(drawText, drawX, drawY);
        });
        return true;
    }

    function wrapParagraph(text, maxWidthPx, style) {
        const content = String(text || '');
        if (!content) return [''];
        const words = content.split(/(\s+)/).filter(p => p !== '');
        const lines = [];
        let current = '';
        words.forEach((piece) => {
            const candidate = current + piece;
            if (!current || measureTextWidth(candidate, style) <= maxWidthPx) { current = candidate; return; }
            if (current.trim()) { lines.push(current.trimEnd()); current = piece.trimStart(); return; }
            let remainder = piece;
            while (remainder) {
                let splitIndex = remainder.length;
                while (splitIndex > 1 && measureTextWidth(remainder.slice(0, splitIndex), style) > maxWidthPx) splitIndex--;
                lines.push(remainder.slice(0, splitIndex));
                remainder = remainder.slice(splitIndex);
            }
            current = '';
        });
        if (current || !lines.length) lines.push(current.trimEnd());
        return lines;
    }

    function buildEditedLines(ann, text, scale, pageWidthPts, pageHeightPts) {
        const box = resolveAnnBox(ann);
        if (!box) return [];
        const offset = annotationSourceOffset(ann);
        // Mirror renderPlainEditorHTML: single \n are soft breaks (reflow), \n\n+ are hard.
        const normalized = String(text ?? '')
            .replace(/\r\n?/g, '\n')
            .replace(/\n{2,}/g, '\x00')
            .replace(/\n/g, ' ')
            .replace(/\x00/g, '\n');
        const paragraphs = normalized.split('\n');
        const lineBBoxes = renderableSourceLines(ann).map((line) => line.bbox).filter(Array.isArray);
        const lines = [];
        const maxWidthPx = Math.max(10, box.w * scale);
        let cursorTopPts = lineBBoxes.length > 0
            ? Number(lineBBoxes[0][1]) + offset.dy
            : (pageHeightPts - box.y - box.h);

        paragraphs.forEach((para, pi) => {
            const style = editableLineStyle(ann, Math.min(pi, Math.max(0, lineBBoxes.length - 1)));
            const stylePx = { ...style, fontSizePx: style.fontSizePt * scale };
            const wrapped = wrapParagraph(para, maxWidthPx, stylePx);
            wrapped.forEach((lineText, wi) => {
                const si = lines.length;
                const sourceBBox = Array.isArray(lineBBoxes[si]) ? lineBBoxes[si] : null;
                const lineStyle = editableLineStyle(ann, Math.min(si, Math.max(0, lineBBoxes.length - 1)));
                const lineStylePx = { ...lineStyle, fontSizePx: lineStyle.fontSizePt * scale };
                const lineHeightPx = blockLineHeightPx(ann, Math.min(si, Math.max(0, lineBBoxes.length - 1)), scale, lineStyle);
                const topPts = sourceBBox
                    ? Number(sourceBBox[1]) + offset.dy
                    : (cursorTopPts + ((wi === 0 && pi === 0 && lines.length === 0) ? 0 : (lineHeightPx / scale)));
                const baselinePts = (() => {
                    const sp = lineSpans(ann, si)[0] || null;
                    if (Array.isArray(sp?.origin) && sp.origin.length >= 2) return Number(sp.origin[1]) + offset.dy;
                    return topPts + (lineHeightPx / scale) * 0.82;
                })();
                lines.push({ text: lineText, xPts: box.x, topPts, baselinePts, lineHeightPx, style: lineStylePx });
                cursorTopPts = topPts;
            });
        });
        return lines;
    }

    // True if the saved ann.text has been edited away from its originalText (the value at extraction).
    function annTextIsEdited(ann) {
        const saved    = String(ann.text ?? '');
        const original = String(ann.originalText ?? '');
        if (!original) return false; // no baseline to compare against
        const norm = s => s.replace(/[\s\n]+/g, ' ').trim();
        return norm(saved) !== norm(original);
    }

    function drawEditedAnnotation(ann, ctx, scale, pageWidthPts, pageHeightPts) {
        if (!resolveAnnBox(ann)) return;
        // currentText: what is being typed right now, or the saved value
        const currentText = String(editedTexts[ann._uid] ?? ann.text ?? '');
        const hasSourceContent = (Array.isArray(ann?.sourceSpans) && ann.sourceSpans.length > 0)
            || (Array.isArray(ann?.sourceLineBBoxes) && ann.sourceLineBBoxes.length > 0);
        // Skip canvas drawing for user-created text annotations with no content:
        // they are shown purely through the active-editor overlay while being created,
        // and drawing a white erase rect here would cover underlying PDF content.
        if (!hasSourceContent && !currentText.trim()) return;
        // Determine if there is any difference from the original PDF source text.
        // This covers both: (a) mid-session edits and (b) saved edits loaded on reload.
        const editedInSession = editedTexts[ann._uid] !== undefined
            && editedTexts[ann._uid] !== String(ann.text ?? '');
        const savedDiffersFromPdf = annTextIsEdited(ann);
        const dimensionsChanged = annotationDimensionsChanged(ann);
        // styleChanged: user changed a visual property (color, background) via format bar.
        // Forces the "edited" drawing path so the canvas reflects the new style even when
        // the annotation text hasn't been changed.
        const annBgColor = String(ann.backgroundColor || '').trim();
        const annOpacity = Math.min(1, Math.max(0, parseFloat(ann.opacity ?? 1)));
        // styleChanged: only force the "edited" redraw path for properties that
        // drawOriginalSource cannot handle (bg color, sub-1 opacity, underline strokes).
        // textColor is already applied inside drawOriginalSource, so it does NOT belong here.
        const styleChanged = hasSourceContent && Boolean(
            (annBgColor && annBgColor !== '#ffffff' && annBgColor !== 'transparent')
            || annOpacity < 0.999
            || ann.underline
            || (String(ann.verticalAlign || 'top').toLowerCase() !== 'top')
            || (String(ann.textAlign || 'left').toLowerCase() !== 'left')
        );
        const { dx: posDx, dy: posDy } = annotationOffset(ann);
        const positionChanged = Math.abs(posDx) > 0.25 || Math.abs(posDy) > 0.25;
        if (!editedInSession && !savedDiffersFromPdf && !dimensionsChanged && !styleChanged && !ann._richHtml) {
            // When only position changed, erase old source area before drawing at new position
            if (positionChanged) {
                const sL = Number(ann.sourceBlockLeft), sT = Number(ann.sourceBlockTop);
                const sW = Number(ann.sourceBlockWidth), sH = Number(ann.sourceBlockHeight);
                if (sW > 0 && sH > 0) {
                    ctx.save();
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(sL * scale, sT * scale, sW * scale, sH * scale);
                    ctx.restore();
                }
            }
            if (drawOriginalSource(ann, ctx, scale)) return;
        }
        // Erase the original PDF background text by filling a white rect over the annotation area
        // (covers both original source area and current/moved position)
        const box = resolveAnnBox(ann);
        const canvasHeight = ctx.canvas.height;
        // Erase original source area (in case the annotation was also moved)
        if (hasSourceContent && positionChanged) {
            const sL = Number(ann.sourceBlockLeft), sT = Number(ann.sourceBlockTop);
            const sW = Number(ann.sourceBlockWidth), sH = Number(ann.sourceBlockHeight);
            if (sW > 0 && sH > 0) {
                ctx.save();
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(sL * scale, sT * scale, sW * scale, sH * scale);
                ctx.restore();
            }
        }
        const eraseLeft   = box.x * scale;
        const eraseTop    = canvasHeight - (box.y + box.h) * scale;
        const eraseW      = box.w * scale;
        const eraseH      = box.h * scale;
        if (hasSourceContent) {
            ctx.save();
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(eraseLeft, eraseTop, eraseW, eraseH);
            ctx.restore();
        }
        const lines = buildEditedLines(ann, currentText, scale, pageWidthPts, pageHeightPts);
        const annTextAlign = ann.textAlign || 'left';
        // Vertical alignment: offset all baselines by Δ so the block of text is aligned
        // top / middle / bottom within the annotation box. Only applied when the lines
        // don't already have anchored source bboxes (plain text edits); for the default
        // 'top' case Δ is 0 so source-anchored positions are unchanged.
        const vAlignRaw = String(ann.verticalAlign || 'top').toLowerCase();
        const vAlignDyPx = (() => {
            if (vAlignRaw === 'top' || !lines.length) return 0;
            const blockHeightPx = lines.reduce((sum, l) => sum + (l.lineHeightPx || 0), 0);
            const boxHeightPx = box.h * scale;
            if (blockHeightPx >= boxHeightPx - 0.5) return 0;
            const slackPx = boxHeightPx - blockHeightPx;
            if (vAlignRaw === 'middle' || vAlignRaw === 'center') return slackPx / 2;
            if (vAlignRaw === 'bottom') return slackPx;
            return 0;
        })();
        ctx.save();
        ctx.globalAlpha = annOpacity;
        // Draw background color (if explicitly set and not white)
        if (annBgColor && annBgColor !== '#ffffff' && annBgColor !== 'transparent') {
            ctx.fillStyle = annBgColor;
            ctx.fillRect(eraseLeft, eraseTop, eraseW, eraseH);
        }
        lines.forEach((line) => {
            // Apply ann-level font overrides on top of span-derived style (format-bar B/I)
            const renderWeight = ann.fontWeight || line.style.fontWeight;
            const renderFontStyle = ann.fontStyle || line.style.fontStyle;
            ctx.font         = ctxFont({ ...line.style, fontWeight: renderWeight, fontStyle: renderFontStyle });
            ctx.fillStyle    = line.style.fillStyle;
            ctx.textBaseline = 'alphabetic';
            const lineWidthPx = line.text ? ctx.measureText(line.text).width : 0;
            let drawX = line.xPts * scale;
            if (annTextAlign !== 'left' && line.text) {
                const boxWidthPx  = box.w * scale;
                if (annTextAlign === 'center') {
                    drawX = (box.x * scale) + (boxWidthPx - lineWidthPx) / 2;
                } else if (annTextAlign === 'right') {
                    drawX = (box.x * scale) + boxWidthPx - lineWidthPx;
                }
            }
            const drawY = (line.baselinePts * scale) + vAlignDyPx;
            // When per-selection formatting is present (ann._richHtml), the canvas only
            // erases the background and paints the opaque bg color — the rich-html-layer
            // DIV renders the styled text on top so the canvas and editor use the same
            // DOM layout. Skip fillText here to avoid doubling up.
            if (!ann._richHtml) {
                ctx.fillText(line.text, drawX, drawY);
                if (ann.underline && line.text) {
                    const fontSize = line.style.fontSizePt * scale;
                    ctx.strokeStyle = line.style.fillStyle;
                    ctx.lineWidth = Math.max(0.5, fontSize * 0.07);
                    ctx.beginPath();
                    ctx.moveTo(drawX, drawY + Math.ceil(fontSize * 0.12));
                    ctx.lineTo(drawX + lineWidthPx, drawY + Math.ceil(fontSize * 0.12));
                    ctx.stroke();
                }
            }
        });
        ctx.restore();
    }

    // Builds the rich-html layer for a page: renders a positioned <div> mirroring the
    // active-editor styling for every non-active annotation that has ann._richHtml set.
    // Called at the end of redrawOverlay so the DOM layer stays in sync with the canvas.
    function renderRichHtmlLayer(pi) {
        const data = pageData[pi];
        const layer = document.getElementById('rhl-' + (pi + 1));
        if (!data || !layer) return;
        const { scale, canvasHeight, annotations } = data;
        layer.innerHTML = '';
        annotations.forEach((ann) => {
            if (!ann._richHtml) return;
            if (ann._uid === activeState.uid) return; // shown via active editor
            const box = resolveAnnBox(ann);
            if (!box) return;
            const rect = annRectPx(ann, scale, canvasHeight);
            const left   = rect ? rect.left : (box.x * scale);
            const top    = rect ? rect.top  : (canvasHeight - (box.y + box.h) * scale);
            const width  = rect ? Math.max(2, rect.width)  : Math.max(2, box.w * scale);
            const height = rect ? Math.max(2, rect.height) : Math.max(2, box.h * scale);
            const style0 = editableLineStyle(ann, 0);
            const lineHeightPx = blockLineHeightPx(ann, 0, scale, style0);
            const bg = String(ann.backgroundColor || '').trim();
            const bgCss = (bg && bg !== '#ffffff' && bg !== 'transparent') ? bg : 'transparent';
            const vAlign = (() => {
                const v = String(ann.verticalAlign || 'top').toLowerCase();
                if (v === 'middle' || v === 'center') return 'center';
                if (v === 'bottom') return 'flex-end';
                return 'flex-start';
            })();
            const item = document.createElement('div');
            item.className = 'rich-html-item';
            item.style.cssText = [
                'display:flex', 'flex-direction:column',
                `justify-content:${vAlign}`,
                `left:${left.toFixed(2)}px`, `top:${top.toFixed(2)}px`,
                `width:${width.toFixed(2)}px`, `height:${height.toFixed(2)}px`,
                `font-family:${style0.fontFamily}`,
                `font-size:${(style0.fontSizePt * scale).toFixed(2)}px`,
                `font-weight:${ann.fontWeight || style0.fontWeight || '400'}`,
                `font-style:${ann.fontStyle || style0.fontStyle || 'normal'}`,
                `text-decoration:${ann.underline ? 'underline' : 'none'}`,
                `color:${style0.fillStyle}`,
                `line-height:${lineHeightPx.toFixed(2)}px`,
                `background:${bgCss}`,
                `text-align:${ann.textAlign || 'left'}`,
                `opacity:${parseFloat(ann.opacity ?? 1)}`,
            ].join(';');
            item.innerHTML = ann._richHtml;
            layer.appendChild(item);
        });
    }
    function redrawOverlay(pi) {
        const data = pageData[pi];
        if (!data) return;
        const canvas = document.getElementById('oc-' + (pi + 1));
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        ctx.clearRect(0, 0, canvas.width, canvas.height);
        const { scale, canvasHeight, annotations, wPts, hPts } = data;

        annotations.forEach((ann) => {
            if (ann._uid === activeState.uid) return; // active annotation shown in editor div
            drawEditedAnnotation(ann, ctx, scale, wPts, hPts);
        });

        if (editModeEnabled) {
            annotations.forEach((ann) => {
                const rect = annRectPx(ann, scale, canvasHeight);
                if (!rect) return;
                ctx.save();
                ctx.strokeStyle = 'rgba(96, 165, 250, 0.95)';
                ctx.lineWidth = 1;
                ctx.setLineDash([5, 4]);
                ctx.fillStyle = 'rgba(147, 197, 253, 0.08)';
                ctx.fillRect(rect.left, rect.top, rect.width, rect.height);
                ctx.strokeRect(rect.left, rect.top, rect.width, rect.height);
                ctx.restore();
            });
        } else if (addTextMode) {
            // In addTextMode, only show outlines for user-created annotations
            // (not extracted ones) so existing PDF content looks untouched.
            annotations.filter(a => a.userCreated && a._uid !== activeState.uid).forEach((ann) => {
                const rect = annRectPx(ann, scale, canvasHeight);
                if (!rect) return;
                ctx.save();
                ctx.strokeStyle = 'rgba(96, 165, 250, 0.95)';
                ctx.lineWidth = 1;
                ctx.setLineDash([5, 4]);
                ctx.fillStyle = 'rgba(147, 197, 253, 0.08)';
                ctx.fillRect(rect.left, rect.top, rect.width, rect.height);
                ctx.strokeRect(rect.left, rect.top, rect.width, rect.height);
                ctx.restore();
            });
        }

        // Hover highlight
        if ((editModeEnabled || addTextMode) && hoverState.pi === pi && hoverState.uid && hoverState.uid !== activeState.uid) {
            const hAnn = annotations.find(a => a._uid === hoverState.uid);
            const rect = hAnn ? annRectPx(hAnn, scale, canvasHeight) : null;
            if (rect) {
                ctx.save();
                ctx.fillStyle   = 'rgba(37, 99, 235, 0.10)';
                ctx.strokeStyle = 'rgba(37, 99, 235, 0.55)';
                ctx.lineWidth   = 1;
                ctx.setLineDash([4, 4]);
                ctx.fillRect(rect.left, rect.top, rect.width, rect.height);
                ctx.strokeRect(rect.left, rect.top, rect.width, rect.height);
                ctx.restore();
            }
        }

        // Active annotation selection outline
        if ((editModeEnabled || addTextMode) && activeState.pi === pi && activeState.uid) {
            const aAnn = annotations.find(a => a._uid === activeState.uid);
            const rect = aAnn ? annRectPx(aAnn, scale, canvasHeight) : null;
            if (rect) {
                ctx.save();
                ctx.strokeStyle = '#2563eb';
                ctx.lineWidth   = 1.5;
                ctx.setLineDash([6, 4]);
                ctx.fillStyle   = 'rgba(37, 99, 235, 0.08)';
                ctx.fillRect(rect.left, rect.top, rect.width, rect.height);
                ctx.strokeRect(rect.left, rect.top, rect.width, rect.height);
                ctx.restore();
            }
        }

        renderRichHtmlLayer(pi);
    }

    // ── Hit testing ───────────────────────────────────────────────────────────
    function findAnnotationAt(x, y, annotations, scale, canvasHeight) {
        const hits = annotations
            .map(ann => ({ ann, rect: annRectPx(ann, scale, canvasHeight) }))
            .filter(({ rect }) => rect && rect.width > 0 && rect.height > 0)
            .filter(({ rect }) => x >= rect.left && x <= rect.left + rect.width && y >= rect.top && y <= rect.top + rect.height)
            .sort((a, b) => (a.rect.width * a.rect.height) - (b.rect.width * b.rect.height));
        return hits.length ? hits[0].ann : null;
    }

    function canvasPointFromEvent(e, canvas) {
        const r = canvas.getBoundingClientRect();
        if (!r.width || !r.height) return null;
        return {
            x: (e.clientX - r.left) * (canvas.width / r.width),
            y: (e.clientY - r.top)  * (canvas.height / r.height),
        };
    }

    // ── Active editor ─────────────────────────────────────────────────────────
    function escapeHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function sourceLineRectWithinAnnotation(ann, lineIndex, scale) {
        const lineBBox = Array.isArray(renderableSourceLines(ann)[lineIndex]?.bbox) ? renderableSourceLines(ann)[lineIndex].bbox : null;
        const visualOrigin = sourceVisualOriginPts(ann);
        if (!lineBBox || !visualOrigin) return null;
        const sourceOffset = annotationSourceOffset(ann);
        const lineLeft = Number(lineBBox[0]) + sourceOffset.dx;
        const lineTop = Number(lineBBox[1]) + sourceOffset.dy;
        const lineRight = Number(lineBBox[2]) + sourceOffset.dx;
        const lineBottom = Number(lineBBox[3]) + sourceOffset.dy;
        if (![lineLeft, lineTop, lineRight, lineBottom].every(Number.isFinite)) return null;

        return {
            left: Math.max(0, (lineLeft - visualOrigin.left) * scale),
            top: Math.max(0, (lineTop - visualOrigin.top) * scale),
            width: Math.max(2, (lineRight - lineLeft) * scale),
            height: Math.max(2, (lineBottom - lineTop) * scale),
        };
    }

    function editorSpanStyle(ann, span, scale) {
        // Priority mirrors drawOriginalSource: ann-level format-bar overrides win over span defaults.
        const spanFontWeight = String(span?.font_weight || span?.fontWeight || (span?.bold ? '700' : '400') || '400');
        const spanFontStyle  = span?.fontStyle || (span?.italic ? 'italic' : 'normal');
        const annFontFamilyOverride = String(ann?.fontFamily || '').trim();
        const resolvedFontFamily = annFontFamilyOverride
            ? fallbackFontFamily(annFontFamilyOverride, annFontFamilyOverride)
            : fallbackFontFamily(span?.embedded_font_name || span?.font, span?.embedded_font_family || span?.fontFamily || '');
        return {
            fontFamily: resolvedFontFamily,
            fontSizePx: (Number(span?.font_size ?? span?.fontSize) || Number(ann?.fontSize) || 12) * scale,
            fontWeight: String(ann?.fontWeight || spanFontWeight),
            fontStyle:  ann?.fontStyle || spanFontStyle || 'normal',
            color: String(ann?.textColor || span?.hex_color || '#000000'),
        };
    }

    function renderEditorSpanHtml(style, text) {
        return `<span style="font-family:${style.fontFamily};font-size:${style.fontSizePx.toFixed(2)}px;font-weight:${style.fontWeight};font-style:${style.fontStyle};color:${style.color};white-space:pre;">${escapeHtml(text)}</span>`;
    }

    function buildLineInnerHtml(ann, lineIndex, scale, spans) {
        if (!Array.isArray(spans) || !spans.length) return '';

        const lineBBox = Array.isArray(renderableSourceLines(ann)[lineIndex]?.bbox) ? renderableSourceLines(ann)[lineIndex].bbox : null;
        let cursorPts = lineBBox ? Number(lineBBox[0]) : null;
        let html = '';

        spans.forEach((span) => {
            const drawText = String(span?.render_text ?? span?.text ?? '');
            if (!drawText) return;

            const style = editorSpanStyle(ann, span, scale);
            const bbox = Array.isArray(span?.bbox) ? span.bbox : null;
            const origin = Array.isArray(span?.origin) ? span.origin : null;
            // Mirror drawOriginalSource: use origin[0] as the span's x position when
            // available, falling back to bbox[0] then cursorPts.
            // This matches the canvas which draws at origin[0] coordinates.
            const spanStartPts = origin ? Number(origin[0]) : (bbox ? Number(bbox[0]) : cursorPts);

            if (cursorPts !== null && Number.isFinite(spanStartPts)) {
                const gapPts = spanStartPts - cursorPts;
                if (gapPts > 0.25) {
                    const measuredSpacePx = Math.max(
                        1,
                        (Number(span?.space_width) || 0) * scale,
                        measureTextWidth(' ', {
                            fontFamily: style.fontFamily,
                            fontSizePx: style.fontSizePx,
                            fontWeight: style.fontWeight,
                            fontStyle: style.fontStyle,
                        }) || 0
                    );
                    const gapPx = gapPts * scale;
                    const spaceCount = Math.max(1, Math.round(gapPx / measuredSpacePx));
                    html += renderEditorSpanHtml(style, ' '.repeat(spaceCount));
                }
            }

            html += renderEditorSpanHtml(style, drawText);

            // Advance cursor from span's origin by the bbox width — mirrors how
            // drawOriginalSource occupies space: drawn at origin[0], occupies bboxWidth pts.
            if (Number.isFinite(spanStartPts) && bbox) {
                cursorPts = spanStartPts + (Number(bbox[2]) - Number(bbox[0]));
            } else if (Number.isFinite(spanStartPts)) {
                cursorPts = spanStartPts + (measureTextWidth(drawText, {
                    fontFamily: style.fontFamily,
                    fontSizePx: style.fontSizePx,
                    fontWeight: style.fontWeight,
                    fontStyle: style.fontStyle,
                }) / scale);
            }
        });

        return html;
    }

    function buildSourceLineEditorHTML(ann, lineIndex, scale) {
        const spans = lineSpans(ann, lineIndex);
        const style = compositeLineStyle(ann, lineIndex);
        const lineHeightPx = blockLineHeightPx(ann, lineIndex, scale);
        const topShiftPx = editorLineTopShiftPx(ann, lineIndex, scale);
        const rect = sourceLineRectWithinAnnotation(ann, lineIndex, scale);
        const scaleX = editorLineScaleX(ann, lineIndex, scale, spans);
        const lineRotation = sourceLineRotationDegrees(ann, lineIndex);
        const wrapperWidthPx = rect
            ? (Math.abs(lineRotation) === 90 ? Math.max(2, rect.height) : Math.max(2, rect.width))
            : null;
        const wrapperHeightPx = rect
            ? (Math.abs(lineRotation) === 90 ? Math.max(lineHeightPx, rect.width || 0) : Math.max(lineHeightPx, rect.height || 0))
            : lineHeightPx;
        const wrapperTransform = (() => {
            const transforms = [];
            if (Math.abs(lineRotation) === 90 && rect) {
                if (lineRotation > 0) {
                    transforms.push(`translateX(${Math.max(2, rect.width).toFixed(2)}px)`);
                    transforms.push('rotate(90deg)');
                } else {
                    transforms.push(`translateY(${Math.max(2, rect.height).toFixed(2)}px)`);
                    transforms.push('rotate(-90deg)');
                }
            }
            if (topShiftPx > 0.01) {
                transforms.push(`translateY(-${topShiftPx.toFixed(2)}px)`);
            }
            return transforms.length ? transforms.join(' ') : 'none';
        })();
        const lineWrapperStyle = [
            'position:absolute',
            rect ? `left:${rect.left.toFixed(2)}px` : 'left:0',
            rect ? `top:${rect.top.toFixed(2)}px` : 'top:0',
            wrapperWidthPx !== null ? `width:${wrapperWidthPx.toFixed(2)}px` : 'width:100%',
            `height:${wrapperHeightPx.toFixed(2)}px`,
            `min-height:${wrapperHeightPx.toFixed(2)}px`,
            `font-family:${style.fontFamily}`,
            `font-size:${(style.fontSizePt * scale).toFixed(2)}px`,
            `font-weight:${style.fontWeight}`,
            `font-style:${style.fontStyle}`,
            `line-height:${lineHeightPx.toFixed(2)}px`,
            `color:${style.fillStyle}`,
            `transform:${wrapperTransform}`,
            'transform-origin:top left',
            'padding:0',
            'margin:0',
            'white-space:normal',
            'overflow:visible',
        ].join(';');
        const contentStyle = [
            'display:inline-block',
            'width:max-content',
            'min-width:max-content',
            `transform:scaleX(${scaleX.toFixed(4)})`,
            'transform-origin:top left',
            'white-space:pre',
            'overflow-wrap:normal',
            'word-break:normal',
            'padding:0',
            'margin:0',
        ].join(';');

        if (!spans.length) {
            const fallbackLine = String(renderableSourceLines(ann)[lineIndex]?.text ?? '');
            return `<div data-line-index="${lineIndex}" style="${lineWrapperStyle}"><span data-line-content="1" style="${contentStyle}">${escapeHtml(fallbackLine) || '<br>'}</span></div>`;
        }

        const innerHtml = buildLineInnerHtml(ann, lineIndex, scale, spans);

        return `<div data-line-index="${lineIndex}" style="${lineWrapperStyle}"><span data-line-content="1" style="${contentStyle}">${innerHtml || '<br>'}</span></div>`;
    }

    // Build innerHTML that mirrors the extracted source lines as closely as possible:
    // one block per extracted line, preserving line heights plus per-span inline styles.
    function buildEditorHTML(ann, scale) {
        const lineBBoxes = renderableSourceLines(ann);
        if (lineBBoxes.length) {
            return lineBBoxes.map((_, lineIndex) => buildSourceLineEditorHTML(ann, lineIndex, scale)).join('');
        }

        const spans = Array.isArray(ann?.sourceSpans) ? ann.sourceSpans : [];
        if (!spans.length) return escapeHtml(String(ann.text ?? ''));

        const groupedLines = [];
        let currentLine = [];
        let prevY = null;

        spans.forEach((span) => {
            const origin = Array.isArray(span?.origin) ? span.origin : null;
            const drawText = String(span?.render_text ?? span?.text ?? '');
            if (!drawText) return;
            const spanY = origin ? Number(origin[1]) : prevY;
            if (prevY !== null && spanY !== null && Math.abs(spanY - prevY) > 1 && currentLine.length) {
                groupedLines.push(currentLine);
                currentLine = [];
            }
            currentLine.push(span);
            if (spanY !== null) prevY = spanY;
        });

        if (currentLine.length) groupedLines.push(currentLine);

        if (!groupedLines.length) return escapeHtml(String(ann.text ?? ''));

        return groupedLines.map((lineSpansForHtml, lineIndex) => {
            const style = compositeLineStyle(ann, lineIndex);
            const lineHeightPx = blockLineHeightPx(ann, lineIndex, scale);
            const topShiftPx = editorLineTopShiftPx(ann, lineIndex, scale);
            const rect = sourceLineRectWithinAnnotation(ann, lineIndex, scale);
            const innerHtml = buildLineInnerHtml(ann, lineIndex, scale, lineSpansForHtml);
            const scaleX = editorLineScaleX(ann, lineIndex, scale, lineSpansForHtml);
            const lineRotation = sourceLineRotationDegrees(ann, lineIndex);
            const wrapperWidthPx = rect
                ? (Math.abs(lineRotation) === 90 ? Math.max(2, rect.height) : Math.max(2, rect.width))
                : null;
            const wrapperHeightPx = rect
                ? (Math.abs(lineRotation) === 90 ? Math.max(lineHeightPx, rect.width || 0) : Math.max(lineHeightPx, rect.height || 0))
                : lineHeightPx;
            const wrapperTransform = (() => {
                const transforms = [];
                if (Math.abs(lineRotation) === 90 && rect) {
                    if (lineRotation > 0) {
                        transforms.push(`translateX(${Math.max(2, rect.width).toFixed(2)}px)`);
                        transforms.push('rotate(90deg)');
                    } else {
                        transforms.push(`translateY(${Math.max(2, rect.height).toFixed(2)}px)`);
                        transforms.push('rotate(-90deg)');
                    }
                }
                if (topShiftPx > 0.01) {
                    transforms.push(`translateY(-${topShiftPx.toFixed(2)}px)`);
                }
                return transforms.length ? transforms.join(' ') : 'none';
            })();
            return `<div data-line-index="${lineIndex}" style="position:absolute;left:${(rect?.left || 0).toFixed(2)}px;top:${(rect?.top || 0).toFixed(2)}px;${wrapperWidthPx !== null ? `width:${wrapperWidthPx.toFixed(2)}px;` : 'width:100%;'}height:${wrapperHeightPx.toFixed(2)}px;min-height:${wrapperHeightPx.toFixed(2)}px;font-family:${style.fontFamily};font-size:${(style.fontSizePt * scale).toFixed(2)}px;font-weight:${style.fontWeight};font-style:${style.fontStyle};text-decoration:${ann.underline ? 'underline' : 'none'};line-height:${lineHeightPx.toFixed(2)}px;color:${style.fillStyle};transform:${wrapperTransform};transform-origin:top left;padding:0;margin:0;white-space:normal;overflow:visible;"><span data-line-content="1" style="display:inline-block;width:max-content;min-width:max-content;transform:scaleX(${scaleX.toFixed(4)});transform-origin:top left;white-space:pre;overflow-wrap:normal;word-break:normal;padding:0;margin:0;">${innerHtml || '<br>'}</span></div>`;
        }).join('');
    }

    function renderPlainEditorHTML(ann, text, scale) {
        const maxSourceIndex = Math.max(0, renderableSourceLines(ann).length - 1);
        const style = editableLineStyle(ann, 0);
        const lineHeightPx = blockLineHeightPx(ann, 0, scale, style);
        const topShiftPx = editorLineTopShiftPx(ann, 0, scale, style);
        const renderWeight = ann.fontWeight || style.fontWeight;
        const renderFontStyle = ann.fontStyle || style.fontStyle;
        const lineAlign = ann.textAlign || 'left';
        // Normalize extracted/user newlines so the box width drives wrap:
        //   \n\n+ → hard paragraph break (visible <br>)
        //   single \n → soft break (space) → reflows to bounding box width
        // This ensures text re-flows when the annotation box is resized, while still
        // letting the user make explicit paragraph breaks by pressing Enter twice.
        const normalized = String(text ?? '')
            .replace(/\r\n?/g, '\n')
            .replace(/\n{2,}/g, '\x00')
            .replace(/\n/g, ' ')
            .replace(/\x00/g, '\n');
        const paragraphs = normalized.split('\n');
        const innerHtml = paragraphs
            .map((p) => escapeHtml(p) || '<br>')
            .join('<br><br>');
        // Suppress maxSourceIndex lint noise — retained for future per-line overrides.
        void maxSourceIndex;
        return `<div data-line-index="0" style="font-family:${style.fontFamily};font-size:${(style.fontSizePt * scale).toFixed(2)}px;font-weight:${renderWeight};font-style:${renderFontStyle};text-decoration:${ann.underline ? 'underline' : 'none'};line-height:${lineHeightPx.toFixed(2)}px;min-height:${lineHeightPx.toFixed(2)}px;color:${style.fillStyle};text-align:${lineAlign};transform:translateY(-${topShiftPx.toFixed(2)}px);transform-origin:top left;padding:0;margin:0;white-space:normal;overflow-wrap:break-word;word-break:break-word;">${innerHtml}</div>`;
    }

    function measureEditedTextHeightPts(ann, text, scale) {
        // Mirror the wrap logic in buildEditedLines / renderPlainEditorHTML so the
        // measured height reflects the actual number of rendered (wrapped) lines,
        // not just the count of `\n`s in the source string. Otherwise a long
        // single-line paragraph collapses the annotation box to one line-height
        // when the user changes font size.
        const maxSourceIndex = Math.max(0, renderableSourceLines(ann).length - 1);
        const box = resolveAnnBox(ann);
        const normalized = String(text ?? '')
            .replace(/\r\n?/g, '\n')
            .replace(/\n{2,}/g, '\x00')
            .replace(/\n/g, ' ')
            .replace(/\x00/g, '\n');
        const paragraphs = normalized.split('\n');
        const maxWidthPx = Math.max(10, (box ? box.w : 0) * scale);
        let totalLineCount = 0;
        const perLineHeightPx = [];
        paragraphs.forEach((para, pIdx) => {
            const lineIndex = Math.min(pIdx, maxSourceIndex);
            const style = editableLineStyle(ann, lineIndex);
            const stylePx = { ...style, fontSizePx: style.fontSizePt * scale };
            const wrapped = box ? wrapParagraph(para, maxWidthPx, stylePx) : [para];
            wrapped.forEach(() => {
                const si = Math.min(totalLineCount, maxSourceIndex);
                perLineHeightPx.push(blockLineHeightPx(ann, si, scale, editableLineStyle(ann, si)));
                totalLineCount++;
            });
        });
        if (!perLineHeightPx.length) {
            perLineHeightPx.push(blockLineHeightPx(ann, 0, scale, editableLineStyle(ann, 0)));
        }
        const totalHeightPx = perLineHeightPx.reduce((a, b) => a + b, 0);
        return Math.max(1, totalHeightPx / Math.max(scale, 0.0001));
    }

    function resizeAnnotationForEditedText(ann, text, pi) {
        const data = pageData[pi];
        const box = data ? resolveAnnBox(ann) : null;
        if (!data || !box) return;

        const topFromPagePts = data.hPts - (box.y + box.h);
        const nextHeightPts = measureEditedTextHeightPts(ann, text, data.scale);
        const nextY = Math.max(0, data.hPts - topFromPagePts - nextHeightPts);

        setAnnotationBox(ann, {
            x: box.x,
            y: nextY,
            w: box.w,
            h: nextHeightPts,
        });
    }

    function syncActiveEditor(forceRebuild = false) {
        const { pi, uid } = activeState;
        const data = pi !== null ? pageData[pi] : null;
        const ann  = data ? data.annotations.find(a => a._uid === uid) : null;

        // Hide editors/handles on all pages when neither edit mode nor addText mode is active.
        if (!editModeEnabled && !addTextMode) {
            Object.keys(pageData).forEach((pIdx) => {
                const pn = Number(pIdx) + 1;
                const ae = document.getElementById('ae-' + pn);
                const tm = document.getElementById('tm-' + pn);
                const rhs = ['nw', 'ne', 'sw', 'se'].map((dir) => document.getElementById(`rh-${pn}-${dir}`));
                if (ae) ae.style.display = 'none';
                if (tm) tm.style.display = 'none';
                rhs.forEach((handle) => { if (handle) handle.style.display = 'none'; });
            });
            return;
        }

        // Hide editors/handles on all other pages
        Object.keys(pageData).forEach(pIdx => {
            if (Number(pIdx) === pi) return;
            const pn = Number(pIdx) + 1;
            const ae = document.getElementById('ae-' + pn);
            const tm = document.getElementById('tm-' + pn);
            const rhs = ['nw', 'ne', 'sw', 'se'].map((dir) => document.getElementById(`rh-${pn}-${dir}`));
            if (ae) ae.style.display = 'none';
            if (tm) tm.style.display = 'none';
            rhs.forEach((handle) => { if (handle) handle.style.display = 'none'; });
        });

        const ae = pi !== null ? document.getElementById('ae-' + (pi + 1)) : null;
        const tm = pi !== null ? document.getElementById('tm-' + (pi + 1)) : null;
        const rhs = pi !== null
            ? ['nw', 'ne', 'sw', 'se'].map((dir) => document.getElementById(`rh-${pi + 1}-${dir}`))
            : [];
        if (!ann || !ae || !tm || rhs.some((handle) => !handle)) return;

        const { scale, canvasHeight } = data;
        const box = resolveAnnBox(ann);
        if (!box) {
            ae.style.display = 'none';
            tm.style.display = 'none';
            rhs.forEach((handle) => { handle.style.display = 'none'; });
            return;
        }

        const displayRect = annRectPx(ann, scale, canvasHeight);
        const left   = displayRect ? displayRect.left : (box.x * scale);
        const top    = displayRect ? displayRect.top : (canvasHeight - (box.y + box.h) * scale);
        const width  = displayRect ? Math.max(2, displayRect.width) : Math.max(2, box.w * scale);
        const height = displayRect ? Math.max(18, displayRect.height) : Math.max(18, box.h * scale);

        // Use editableLineStyle so ann-level overrides (format-bar font size / family / color)
        // drive the outer container, keeping the caret + any unwrapped text node in sync with
        // the format bar as soon as a slider change fires — not only after blur/reopen.
        const style0       = editableLineStyle(ann, 0);
        const lineHeightPx = blockLineHeightPx(ann, 0, scale, style0);
        const sourceRotation = annotationSourceRotationDegrees(ann);

        const aeBgColor = (() => {
            const bg = String(ann.backgroundColor || '').trim();
            return (bg && bg !== '#ffffff' && bg !== 'transparent') ? bg : 'transparent';
        })();
        // Vertical alignment: use flex on the outer container so inner content is pushed
        // to top / middle / bottom of the annotation box. The editor HTML remains a single
        // block inside, so flex vertically positions the whole block without disrupting
        // text wrap or caret behavior.
        const vAlign = (() => {
            const v = String(ann.verticalAlign || 'top').toLowerCase();
            if (v === 'middle' || v === 'center') return 'center';
            if (v === 'bottom') return 'flex-end';
            return 'flex-start';
        })();
        ae.style.cssText = [
            'display:flex', 'flex-direction:column',
            `justify-content:${vAlign}`,
            'position:absolute',
            `left:${left.toFixed(2)}px`, `top:${top.toFixed(2)}px`,
            `width:${width.toFixed(2)}px`, `height:${height.toFixed(2)}px`,
            `font-family:${style0.fontFamily}`,
            `font-size:${(style0.fontSizePt * scale).toFixed(2)}px`,
            `font-weight:${ann.fontWeight || style0.fontWeight || '400'}`,
            `font-style:${ann.fontStyle || style0.fontStyle || 'normal'}`,
            `text-decoration:${ann.underline ? 'underline' : 'none'}`,
            `color:${style0.fillStyle}`,
            `line-height:${lineHeightPx.toFixed(2)}px`,
            'padding:0', 'margin:0', `background:${aeBgColor}`,
            `text-align:${ann.textAlign || 'left'}`,
            `opacity:${parseFloat(ann.opacity ?? 1)}`,
            'border:none', 'outline:none', 'overflow:visible',
            'white-space:pre-wrap', 'word-break:break-word',
            'pointer-events:auto', 'user-select:text', 'cursor:text',
            'caret-color:#2563eb', 'z-index:5',
        ].join(';');
        // Mark new-annotation creation state so CSS can show a visible input box + placeholder.
        // A new annotation has no source content and empty text; the green dashed border
        // (via [data-creating]) matches the .text-box-creator style from the edit view.
        const _hasSource = (Array.isArray(ann.sourceSpans) && ann.sourceSpans.length > 0)
            || (Array.isArray(ann.sourceLineBBoxes) && ann.sourceLineBBoxes.length > 0);
        const _annText = String(editedTexts[ann._uid] ?? ann.text ?? '').trim();
        if (!_hasSource && !_annText) {
            ae.dataset.creating = '1';
        } else {
            delete ae.dataset.creating;
        }
        // Only reset content when not focused (avoids caret jump mid-edit).
        // forceRebuild=true is set by format-bar actions so changes are immediately visible.
        if (document.activeElement !== ae || forceRebuild) {
            // Per-selection formatting (via execCommand) is captured in ann._richHtml.
            // Respect it so subsequent syncs don't flatten inline <span style="…"> back to
            // the annotation-level uniform styling. A forceRebuild from annotation-level
            // format changes (font family/size/etc.) still preserves the rich HTML since
            // annotation-level styles apply to the editor container, not the inner spans.
            if (ann._richHtml) {
                ae.dataset.renderMode = 'plain';
                if (ae.innerHTML !== ann._richHtml) ae.innerHTML = ann._richHtml;
            } else {
                const editedText = editedTexts[ann._uid];
                if (editedText !== undefined && (editedText !== String(ann.text ?? '') || annotationDimensionsChanged(ann))) {
                    ae.dataset.renderMode = 'plain';
                    ae.innerHTML = renderPlainEditorHTML(ann, editedText, scale);
                } else {
                    // Detect persisted edits (same logic as annTextIsEdited in canvas draw):
                    // compare ann.text against ann.originalText (the value at extraction).
                    const normWS = s => String(s).replace(/[\s\n]+/g, ' ').trim();
                    const savedText     = String(ann.text ?? '');
                    const originalText  = String(ann.originalText ?? '');
                    const textWasEdited = originalText !== '' && normWS(savedText) !== normWS(originalText);
                    if (textWasEdited) {
                        ae.dataset.renderMode = 'plain';
                        ae.innerHTML = renderPlainEditorHTML(ann, savedText, scale);
                    } else {
                        // Text matches the original extraction — render with per-span styles
                        ae.dataset.renderMode = 'source';
                        ae.innerHTML = buildEditorHTML(ann, scale);
                    }
                }
            }
        }
        if (ae.dataset.renderMode !== 'source' && Math.abs(sourceRotation) === 90 && height > 0 && width > 0) {
            ae.style.width = `${height.toFixed(2)}px`;
            ae.style.height = `${width.toFixed(2)}px`;
            ae.style.transformOrigin = 'top left';
            ae.style.transform = sourceRotation > 0
                ? `translateX(${width.toFixed(2)}px) rotate(90deg)`
                : `translateY(${height.toFixed(2)}px) rotate(-90deg)`;
        } else {
            ae.style.transformOrigin = 'top left';
            ae.style.transform = 'none';
        }
        if (ae.dataset.renderMode === 'source') {
            fitActiveEditorSourceLines(ae, ann, scale);
        }

        const menuTop = top - 42;
        const menuBelow = menuTop < 4;
        tm.classList.toggle('is-below', menuBelow);
        tm.style.cssText = [
            'display:flex',
            'position:absolute',
            `left:${(left + (width / 2)).toFixed(2)}px`,
            `top:${(menuBelow ? (top + height) : menuTop).toFixed(2)}px`,
            'z-index:6',
        ].join(';');

        const handlePositions = {
            nw: { left: left - 6, top: top - 6 },
            ne: { left: left + width - 6, top: top - 6 },
            sw: { left: left - 6, top: top + height - 6 },
            se: { left: left + width - 6, top: top + height - 6 },
        };
        rhs.forEach((handle) => {
            const dir = handle.dataset.dir;
            const pos = handlePositions[dir];
            handle.style.cssText = [
                'display:block',
                'position:absolute',
                `left:${pos.left.toFixed(2)}px`,
                `top:${pos.top.toFixed(2)}px`,
                'width:12px',
                'height:12px',
                'z-index:6',
            ].join(';');
        });
    }

    function clearActiveAnnotation() {
        if (dragState.active) endDrag();
        if (resizeState.active) endResize();
        if (textCreationState.active) cancelTextCreationPreview();
        const prevPi = activeState.pi;
        activeState = { pi: null, uid: null };
        syncActiveEditor();
        updateFormatBar();
        if (prevPi !== null) redrawOverlay(prevPi);
    }

    function selectAnnotation(ann, pi) {
        if ((!editModeEnabled && !addTextMode) || !ann) return;
        const prevPi = activeState.pi;
        activeState = { pi, uid: ann._uid };
        syncActiveEditor();
        updateFormatBar();
        if (prevPi !== null && prevPi !== pi) redrawOverlay(prevPi);
        redrawOverlay(pi);
    }

    function updateFormatBar() {
        const { pi, uid } = activeState;
        const data = pi !== null ? pageData[pi] : null;
        const ann  = data ? data.annotations.find(a => a._uid === uid) : null;
        if (!annFormatBar) return;
        if (!ann) {
            annFormatBar.classList.remove('is-visible');
            return;
        }
        annFormatBar.classList.add('is-visible');
        if (afbFont) {
            const fv = ann.fontFamily || 'Helvetica';
            afbFont.value = fv;
            // If the font isn't in the list, fall back to Helvetica
            if (!afbFont.value) afbFont.value = 'Helvetica';
        }
        const fontSize = Number(ann.fontSize) || 12;
        if (afbSize)      afbSize.value = String(Math.round(Math.min(144, Math.max(6, fontSize))));
        if (afbSizeValue) afbSizeValue.textContent = Math.round(fontSize) + 'pt';
        if (afbTextColor) afbTextColor.value = ann.textColor || '#000000';
        if (afbBgColor)   afbBgColor.value   = ann.backgroundColor || '#ffffff';
        if (afbOpacity) {
            const op = parseFloat(ann.opacity ?? 1);
            const rounded = Math.round((isNaN(op) ? 1 : Math.min(1, Math.max(0.1, op))) * 10) / 10;
            afbOpacity.value = String(rounded);
        }
        if (afbBold) {
            const isBold = String(ann.fontWeight || '400') === '700';
            afbBold.setAttribute('aria-pressed', String(isBold));
            afbBold.classList.toggle('is-active', isBold);
        }
        if (afbItalic) {
            const isItalic = (ann.fontStyle || 'normal') === 'italic';
            afbItalic.setAttribute('aria-pressed', String(isItalic));
            afbItalic.classList.toggle('is-active', isItalic);
        }
        if (afbUnderline) {
            const isUnderline = Boolean(ann.underline);
            afbUnderline.setAttribute('aria-pressed', String(isUnderline));
            afbUnderline.classList.toggle('is-active', isUnderline);
        }
        if (afbAlign)  afbAlign.value  = ann.textAlign     || 'left';
        if (afbValign) afbValign.value = ann.verticalAlign || 'top';
    }

    function updateSaveUi() {
        if (saveButton) {
            saveButton.disabled = isSaving;
            saveButton.textContent = isSaving ? 'Saving...' : 'Save';
        }
        if (downloadPdfButton) {
            downloadPdfButton.disabled = isDownloadingPdf;
            downloadPdfButton.textContent = isDownloadingPdf ? 'Preparing PDF...' : 'Download PDF';
        }
        if (saveStatus) {
            saveStatus.textContent = isDownloadingPdf
                ? 'Preparing PDF...'
                : (isSaving ? 'Saving...' : (isDirty ? 'Unsaved changes' : 'Saved'));
        }
    }

    function redrawAllOverlays() {
        Object.keys(pageData).forEach((pi) => redrawOverlay(Number(pi)));
    }

    function updateEditModeUi() {
        if (editModeToggle) {
            editModeToggle.classList.toggle('is-active', editModeEnabled);
            editModeToggle.setAttribute('aria-pressed', editModeEnabled ? 'true' : 'false');
            editModeToggle.setAttribute(
                'title',
                editModeEnabled
                    ? 'Turn edit mode off to hide text boxes and lock text editing'
                    : 'Turn edit mode on to show editable text boxes'
            );
        }
        if (ftbEditMode) {
            ftbEditMode.classList.toggle('is-active', editModeEnabled);
        }
        if (editModeToggleLabel) {
            editModeToggleLabel.textContent = editModeEnabled ? 'Edit Mode ON' : 'Edit Mode OFF';
        }
        if (addTextBtn) {
            addTextBtn.disabled = editModeEnabled;
            addTextBtn.title = editModeEnabled
                ? 'Turn Edit Mode OFF to add text'
                : 'Add Text — click or drag on the page to place a new text block';
        }
        if (ftbAddText) {
            ftbAddText.classList.toggle('is-disabled', editModeEnabled);
            ftbAddText.title = editModeEnabled
                ? 'Turn Edit Mode OFF to add text'
                : 'Text — click on the page to place a new text block';
        }
    }

    function setEditModeEnabled(active) {
        const nextState = !!active;
        if (editModeEnabled === nextState) return;
        editModeEnabled = nextState;
        setAddTextMode(false); // always exit addTextMode when edit mode changes
        if (!editModeEnabled) {
            hoverState = { pi: null, uid: null };
            clearActiveAnnotation();
        }
        updateEditModeUi();
        redrawAllOverlays();
        if (editModeEnabled && activeState.pi !== null) {
            syncActiveEditor();
        }
    }

    function getEditorPlainText(editor) {
        return String(editor?.innerText ?? '')
            .replace(/\r/g, '')
            .replace(/\u00a0/g, ' ')
            .replace(/\n$/, '');
    }

    function getEditorSelectionOffsets(editor) {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0 || !editor) return null;
        const range = selection.getRangeAt(0);
        if (!editor.contains(range.startContainer) || !editor.contains(range.endContainer)) return null;

        const startRange = document.createRange();
        startRange.selectNodeContents(editor);
        startRange.setEnd(range.startContainer, range.startOffset);

        const endRange = document.createRange();
        endRange.selectNodeContents(editor);
        endRange.setEnd(range.endContainer, range.endOffset);

        return {
            start: startRange.toString().length,
            end: endRange.toString().length,
        };
    }

    function findLineTextPosition(lineEl, charOffset) {
        const walker = document.createTreeWalker(lineEl, NodeFilter.SHOW_TEXT);
        let remaining = Math.max(0, charOffset);
        let lastTextNode = null;

        while (walker.nextNode()) {
            const textNode = walker.currentNode;
            const textLength = textNode.textContent.length;
            lastTextNode = textNode;
            if (remaining <= textLength) {
                return { node: textNode, offset: remaining };
            }
            remaining -= textLength;
        }

        if (lastTextNode) {
            return { node: lastTextNode, offset: lastTextNode.textContent.length };
        }

        return { node: lineEl, offset: lineEl.childNodes.length };
    }

    function setEditorSelectionOffsets(editor, start, end = start) {
        if (!editor) return;
        const lines = Array.from(editor.children);
        if (!lines.length) {
            editor.focus({ preventScroll: true });
            return;
        }

        const locate = (targetOffset) => {
            let remaining = Math.max(0, targetOffset);

            for (let i = 0; i < lines.length; i++) {
                const lineEl = lines[i];
                const lineText = String(lineEl.innerText ?? '').replace(/\r/g, '').replace(/\n$/, '');
                const lineLength = lineText.length;
                if (remaining <= lineLength) {
                    return findLineTextPosition(lineEl, remaining);
                }
                remaining -= lineLength;
                if (i < lines.length - 1) {
                    if (remaining === 0) {
                        return findLineTextPosition(lineEl, lineLength);
                    }
                    remaining -= 1;
                }
            }

            const lastLine = lines[lines.length - 1];
            const lastLineText = String(lastLine.innerText ?? '').replace(/\r/g, '').replace(/\n$/, '');
            return findLineTextPosition(lastLine, lastLineText.length);
        };

        const startPos = locate(start);
        const endPos = locate(end);
        const selection = window.getSelection();
        if (!selection || !startPos || !endPos) return;

        const range = document.createRange();
        range.setStart(startPos.node, startPos.offset);
        range.setEnd(endPos.node, endPos.offset);
        selection.removeAllRanges();
        selection.addRange(range);
        editor.focus({ preventScroll: true });
    }

    function markDirty() {
        isDirty = true;
        updateSaveUi();
    }

    function markClean() {
        isDirty = false;
        updateSaveUi();
    }

    // ── Drag-to-reposition ────────────────────────────────────────────────────
    function pdfPtFromClient(clientX, clientY, pi) {
        const data = pageData[pi];
        if (!data) return null;
        const canvas = document.getElementById('oc-' + (pi + 1));
        if (!canvas) return null;
        const r = canvas.getBoundingClientRect();
        if (!r.width || !r.height) return null;
        const cx = (clientX - r.left) * (canvas.width / r.width);
        const cy = (clientY - r.top)  * (canvas.height / r.height);
        return { x: cx / data.scale, y: (data.canvasHeight - cy) / data.scale };
    }

    function beginDrag(e, pi) {
        if (!editModeEnabled && !addTextMode) return;
        const data = pageData[pi];
        const ann  = data?.annotations.find(a => a._uid === activeState.uid);
        const box  = ann ? resolveAnnBox(ann) : null;
        const pt   = ann ? pdfPtFromClient(e.clientX, e.clientY, pi) : null;
        if (!ann || !box || !pt) return;
        pushUndo();
        dragState = { active: true, pi, uid: ann._uid, offsetXPts: pt.x - box.x, offsetYPts: pt.y - box.y };
    }

    function beginResize(e, pi, handle) {
        if (!editModeEnabled && !addTextMode) return;
        const data = pageData[pi];
        const ann = data?.annotations.find(a => a._uid === activeState.uid);
        const box = ann ? resolveAnnBox(ann) : null;
        const pt = ann ? pdfPtFromClient(e.clientX, e.clientY, pi) : null;
        if (!ann || !box || !pt) return;
        pushUndo();
        const startStyle = editableLineStyle(ann, 0);
        resizeState = {
            active: true,
            pi,
            uid: ann._uid,
            handle,
            startPt: pt,
            startBox: { ...box },
            startFontSize: startStyle.fontSizePt,
        };
    }

    function endDrag() {
        if (!dragState.active) return;
        dragState = { active: false, pi: null, uid: null, offsetXPts: 0, offsetYPts: 0 };
        markDirty();
    }

    function endResize() {
        if (!resizeState.active) return;
        resizeState = { active: false, pi: null, uid: null, handle: null, startPt: null, startBox: null, startFontSize: null };
        markDirty();
    }

    function shouldScaleFontOnResize(ann) {
        return false;
    }

    function scaledResizeFontSize(startFontSize, startBox, nextWidth, nextHeight) {
        const baselineFontSize = Math.max(1, Number(startFontSize) || 12);
        const widthScale = (Number(startBox?.w) || 0) > 0 ? (nextWidth / startBox.w) : 1;
        const heightScale = (Number(startBox?.h) || 0) > 0 ? (nextHeight / startBox.h) : 1;
        const scaleFactor = Math.sqrt(
            Math.max(0.01, Number.isFinite(widthScale) ? widthScale : 1)
            * Math.max(0.01, Number.isFinite(heightScale) ? heightScale : 1)
        );
        return Math.max(6, Math.min(144, baselineFontSize * scaleFactor));
    }

    window.addEventListener('mousemove', (e) => {
        if (dragState.active) {
            const { pi, uid, offsetXPts, offsetYPts } = dragState;
            const data = pageData[pi];
            const ann  = data?.annotations.find(a => a._uid === uid);
            if (!ann) return;
            const box = resolveAnnBox(ann);
            const pt  = pdfPtFromClient(e.clientX, e.clientY, pi);
            if (!box || !pt) return;
            setAnnotationBox(ann, {
                x: Math.min(Math.max(0, pt.x - offsetXPts), Math.max(0, data.wPts - box.w)),
                y: Math.min(Math.max(0, pt.y - offsetYPts), Math.max(0, data.hPts - box.h)),
                w: box.w, h: box.h,
            });
            redrawOverlay(pi);
            syncActiveEditor();
            return;
        }

        if (!resizeState.active) return;
        const { pi, uid, handle, startPt, startBox, startFontSize } = resizeState;
        const data = pageData[pi];
        const ann = data?.annotations.find(a => a._uid === uid);
        const pt = pdfPtFromClient(e.clientX, e.clientY, pi);
        if (!ann || !pt || !startPt || !startBox) return;

        const minWidthPts = 12;
        const currentText = editedTexts[ann._uid] ?? String(ann.text ?? '');
        const right = startBox.x + startBox.w;
        const top = startBox.y + startBox.h;

        let nextLeft = startBox.x;
        let nextRight = right;
        let nextBottom = startBox.y;
        let nextTop = top;

        if (handle.includes('w')) {
            nextLeft = Math.min(pt.x, right - minWidthPts);
        }
        if (handle.includes('e')) {
            nextRight = Math.max(pt.x, startBox.x + minWidthPts);
        }
        if (handle.includes('s')) {
            nextBottom = pt.y;
        }
        if (handle.includes('n')) {
            nextTop = pt.y;
        }

        nextLeft = Math.max(0, nextLeft);
        nextBottom = Math.max(0, nextBottom);
        nextRight = Math.min(data.wPts, nextRight);
        nextTop = Math.min(data.hPts, nextTop);

        let nextWidth = Math.max(minWidthPts, nextRight - nextLeft);
        let nextHeight = Math.max(8, nextTop - nextBottom);

        if (shouldScaleFontOnResize(ann) && startFontSize) {
            const previousFontSize = ann.fontSize;
            ann.fontSize = scaledResizeFontSize(startFontSize, startBox, nextWidth, nextHeight);
            const scaledMinHeightPts = Math.max(8, measureEditedTextHeightPts(ann, currentText, data.scale));
            ann.fontSize = previousFontSize;

            if (handle.includes('s')) {
                nextBottom = Math.min(nextBottom, nextTop - scaledMinHeightPts);
            }
            if (handle.includes('n')) {
                nextTop = Math.max(nextTop, nextBottom + scaledMinHeightPts);
            }
            nextBottom = Math.max(0, nextBottom);
            nextTop = Math.min(data.hPts, nextTop);
            nextHeight = Math.max(scaledMinHeightPts, nextTop - nextBottom);
        } else {
            const minHeightPts = Math.max(8, measureEditedTextHeightPts(ann, currentText, data.scale));
            if (handle.includes('s')) {
                nextBottom = Math.min(nextBottom, nextTop - minHeightPts);
            }
            if (handle.includes('n')) {
                nextTop = Math.max(nextTop, nextBottom + minHeightPts);
            }
            nextBottom = Math.max(0, nextBottom);
            nextTop = Math.min(data.hPts, nextTop);
            nextHeight = Math.max(minHeightPts, nextTop - nextBottom);
        }
        nextWidth = Math.max(minWidthPts, nextRight - nextLeft);

        setAnnotationBox(ann, {
            x: nextLeft,
            y: nextBottom,
            w: nextWidth,
            h: nextHeight,
        });
        if (shouldScaleFontOnResize(ann) && startFontSize) {
            ann.fontSize = scaledResizeFontSize(startFontSize, startBox, nextWidth, nextHeight);
        }

        redrawOverlay(pi);
        syncActiveEditor();
        updateFormatBar();
    });

    window.addEventListener('mouseup', () => {
        if (dragState.active) endDrag();
        if (resizeState.active) endResize();
    });
    window.addEventListener('keydown', (e) => {
        // Undo / Redo — never intercept when focus is inside a text field.
        const inTextField = document.activeElement && (
            document.activeElement.tagName === 'INPUT' ||
            document.activeElement.tagName === 'TEXTAREA' ||
            document.activeElement.isContentEditable
        );
        if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.key === 'z') {
            if (!inTextField) { e.preventDefault(); performUndo(); return; }
        }
        if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.shiftKey && e.key === 'z'))) {
            if (!inTextField) { e.preventDefault(); performRedo(); return; }
        }

        if (!editModeEnabled && !addTextMode) return;
        if (e.key !== 'Delete' && e.key !== 'Backspace') return;
        if (dragState.active || resizeState.active) return;
        if (!activeState.uid || activeState.pi === null) return;
        const ae = document.getElementById('ae-' + (activeState.pi + 1));
        if (ae && document.activeElement === ae) return;
        e.preventDefault();
        const ann = pageData[activeState.pi]?.annotations.find(a => a._uid === activeState.uid);
        if (!ann) return;
        deleteAnnotation(ann, activeState.pi);
    });

    // ── Save ──────────────────────────────────────────────────────────────────
    function showError(msg) { errorBanner.textContent = msg; errorBanner.style.display = 'block'; }

    function showToast(msg) {
        saveToast.textContent = msg; saveToast.classList.add('show');
        clearTimeout(saveToast._t);
        saveToast._t = setTimeout(() => saveToast.classList.remove('show'), 2000);
    }

    function buildPersistedAnnotationPayload(ann) {
        const currentText = editedTexts[ann._uid] ?? String(ann.text ?? '');
        const payload = Object.assign({}, ann, {
            text: currentText,
        });
        if (ann.promotedFromExtraction) {
            payload.promotedDirty = shouldPersistPromotedDirty(ann, currentText);
        }
        if (payload.annotation_data && typeof payload.annotation_data === 'object') {
            payload.annotation_data = Object.assign({}, payload.annotation_data, {
                text: currentText,
                pdfX: payload.pdfX,
                pdfY: payload.pdfY,
                pdfWidth: payload.pdfWidth,
                pdfHeight: payload.pdfHeight,
            });
            if (ann.promotedFromExtraction) {
                payload.annotation_data.promotedDirty = payload.promotedDirty;
            }
        }
        delete payload._originalBox;
        delete payload._originalPdfBox;
        delete payload._styleDirty;
        delete payload._richHtml;
        return payload;
    }

    function normalizeAnnotationTextForDirtyComparison(value) {
        return String(value ?? '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    }

    function annotationPositionChanged(ann) {
        const cur = resolveAnnBox(ann), orig = resolveOriginalAnnBox(ann);
        if (!cur || !orig) return false;
        return Math.abs(cur.x - orig.x) > 0.25 || Math.abs(cur.y - orig.y) > 0.25;
    }

    function shouldPersistPromotedDirty(ann, currentText) {
        if (!ann?.promotedFromExtraction) return false;
        const originalText = normalizeAnnotationTextForDirtyComparison(ann.originalText ?? ann.text ?? '');
        const nextText = normalizeAnnotationTextForDirtyComparison(currentText);
        if (nextText !== originalText) return true;
        if (annotationPositionChanged(ann)) return true;
        if (annotationDimensionsChanged(ann)) return true;
        if (ann._styleDirty) return true;
        return false;
    }

    function flushActiveEditorState() {
        if (activeState.pi === null || !activeState.uid) return;
        const ae = document.getElementById('ae-' + (activeState.pi + 1));
        if (!(ae instanceof HTMLElement) || ae.style.display === 'none') return;
        const ann = pageData[activeState.pi]?.annotations.find(a => a._uid === activeState.uid);
        if (!ann) return;
        const nextText = getEditorPlainText(ae);
        const savedText = String(ann.text ?? '');
        if (nextText === savedText) {
            delete editedTexts[ann._uid];
        } else {
            if (editedTexts[ann._uid] !== nextText) {
                markDirty();
            }
            editedTexts[ann._uid] = nextText;
        }
    }

    function collectSessionAnnotations() {
        return Object.values(pageData)
            .flatMap((data) => Array.isArray(data?.annotations) ? data.annotations : [])
            .map((ann) => buildPersistedAnnotationPayload(ann));
    }

    async function downloadReadyPdf() {
        if (isSaving || isDownloadingPdf) return;
        flushActiveEditorState();

        const popup = window.open('', '_blank');
        if (popup) {
            popup.document.write('<!DOCTYPE html><title>Preparing PDF</title><body style="font-family:system-ui,sans-serif;padding:24px;color:#111827;">Preparing PDF...</body>');
            popup.document.close();
        }

        isDownloadingPdf = true;
        updateSaveUi();

        const sessionAnnotations = collectSessionAnnotations();
        const deletedPromotedSourceKeys = Array.from(pendingDeletedPromotedSourceKeys);

        try {
            const response = await fetch(DOWNLOAD_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/pdf, application/json', 'X-CSRF-TOKEN': CSRF },
                credentials: 'same-origin',
                body: JSON.stringify({
                    annotations: sessionAnnotations,
                    session_annotations: sessionAnnotations,
                    acro_form_entries: acroFormEntries,
                    deleted_promoted_source_keys: deletedPromotedSourceKeys,
                    use_exact_download_path: true,
                    session_id: getSessionId(),
                }),
            });

            if (!response.ok) {
                let message = 'Failed to generate PDF.';
                const contentType = String(response.headers.get('content-type') || '');
                if (contentType.includes('application/json')) {
                    const result = await response.json().catch(() => ({}));
                    message = result.message || message;
                } else {
                    const text = await response.text().catch(() => '');
                    if (text) message = text;
                }
                throw new Error(message);
            }

            const pdfBlob = await response.blob();
            const blobUrl = URL.createObjectURL(pdfBlob);
            if (popup && !popup.closed) {
                popup.location.replace(blobUrl);
            } else {
                window.open(blobUrl, '_blank', 'noopener');
            }
            window.setTimeout(() => URL.revokeObjectURL(blobUrl), 60000);

            Object.values(pageData).forEach((data) => {
                (data.annotations || []).forEach((ann) => {
                    ann.text = editedTexts[ann._uid] ?? String(ann.text ?? '');
                });
            });
            pendingDeletedAnnotationIds.clear();
            pendingDeletedPromotedSourceKeys.clear();
            markClean();
            showToast('PDF ready');
        } catch (error) {
            if (popup && !popup.closed) popup.close();
            showToast(error?.message || 'Failed to generate PDF');
        } finally {
            isDownloadingPdf = false;
            updateSaveUi();
        }
    }

    async function saveAllChanges() {
        if (isSaving) return;
        flushActiveEditorState();
        if (!isDirty && pendingDeletedAnnotationIds.size === 0 && pendingDeletedPromotedSourceKeys.size === 0) {
            showToast('No changes to save');
            return;
        }

        isSaving = true;
        updateSaveUi();

        const sessionAnnotations = collectSessionAnnotations();
        const deletedAnnotationIds = Array.from(pendingDeletedAnnotationIds);
        const deletedPromotedSourceKeys = Array.from(pendingDeletedPromotedSourceKeys);

        try {
            if (deletedAnnotationIds.length || deletedPromotedSourceKeys.length) {
                const deleteResponse = await fetch(DELETE_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        session_id: getSessionId(),
                        annotation_ids: deletedAnnotationIds,
                        deleted_promoted_source_keys: deletedPromotedSourceKeys,
                    }),
                });
                const deleteResult = await deleteResponse.json().catch(() => ({}));
                if (!deleteResponse.ok || !deleteResult.success) {
                    throw new Error(deleteResult.message || 'Delete failed');
                }
            }

            const response = await fetch(SAVE_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
                credentials: 'same-origin',
                body: JSON.stringify({
                    annotations: sessionAnnotations,
                    session_annotations: sessionAnnotations,
                    acro_form_entries: acroFormEntries,
                    deleted_promoted_source_keys: deletedPromotedSourceKeys,
                    session_id: getSessionId(),
                }),
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Save failed');
            }

            Object.values(pageData).forEach((data) => {
                (data.annotations || []).forEach((ann) => {
                    ann.text = editedTexts[ann._uid] ?? String(ann.text ?? '');
                });
            });
            pendingDeletedAnnotationIds.clear();
            pendingDeletedPromotedSourceKeys.clear();
            markClean();
            showToast('Saved');
        } catch (error) {
            showToast(error?.message || 'Save failed');
        } finally {
            isSaving = false;
            updateSaveUi();
        }
    }

    function deleteAnnotation(ann, pi) {
        if (!ann) return;

        const annotationId = String(ann.id || '').trim();
        const promotedSourceKey = ann.promotedFromExtraction ? String(ann.promotedSourceKey || '').trim() : '';
        const data = pageData[pi];
        if (!data) return;

        pushUndo();
        data.annotations = data.annotations.filter((item) => item._uid !== ann._uid);
        if (annotationId) pendingDeletedAnnotationIds.add(annotationId);
        if (promotedSourceKey) pendingDeletedPromotedSourceKeys.add(promotedSourceKey);
        delete editedTexts[ann._uid];
        if (hoverState.uid === ann._uid) hoverState = { pi: null, uid: null };
        clearActiveAnnotation();
        redrawOverlay(pi);
        markDirty();
    }

    // ── Add Text mode ─────────────────────────────────────────────────────────
    function setAddTextMode(active) {
        addTextMode = !editModeEnabled && !!active;
        if (!addTextMode && textCreationState.active) cancelTextCreationPreview();
        if (addTextBtn) addTextBtn.classList.toggle('active', addTextMode);
        if (ftbAddText) ftbAddText.classList.toggle('is-active', addTextMode);
        // Update pointer cursor on every page canvas
        Object.keys(pageData).forEach((piStr) => {
            const oc = document.getElementById('oc-' + (Number(piStr) + 1));
            if (oc) oc.style.cursor = addTextMode ? 'crosshair' : 'default';
        });
    }

    function cancelTextCreationPreview() {
        if (textCreationState.previewEl) {
            textCreationState.previewEl.remove();
        }
        textCreationState = { active: false, pi: null, pointerId: null, startX: 0, startY: 0, rect: null, previewEl: null, moved: false };
    }

    function generateAnnotationId() {
        return 'ann_' + Date.now() + '_' + Math.random().toString(36).slice(2, 11);
    }

    function createNewTextAnnotation(canvasX, canvasY, pi, canvasWidth = null, canvasHeight = null) {
        if (!addTextMode) return;
        const data = pageData[pi];
        if (!data) return;

        const defaultFontSize = 12;   // pts
        const defaultWidth    = 200;  // pts
        const defaultHeight   = Math.ceil(defaultFontSize * 1.5); // pts
        const hasDraggedSize  = Number.isFinite(canvasWidth) && Number.isFinite(canvasHeight);
        const nextCanvasWidth = hasDraggedSize ? Math.max(60, Number(canvasWidth) || 0) : defaultWidth * data.scale;
        const nextCanvasHeight = hasDraggedSize ? Math.max(28, Number(canvasHeight) || 0) : defaultHeight * data.scale;

        const rawX = canvasX / data.scale;
        const rawY = hasDraggedSize
            ? (data.canvasHeight - (canvasY + nextCanvasHeight)) / data.scale
            : ((data.canvasHeight - canvasY) / data.scale) - defaultHeight;
        const nextWidthPts = hasDraggedSize ? (nextCanvasWidth / data.scale) : defaultWidth;
        const nextHeightPts = hasDraggedSize ? (nextCanvasHeight / data.scale) : defaultHeight;

        const x = Math.max(0, Math.min(rawX, Math.max(0, data.wPts - nextWidthPts)));
        const y = Math.max(0, Math.min(rawY, Math.max(0, data.hPts - nextHeightPts)));

        const uid = 'new_' + Date.now() + '_' + Math.random().toString(36).slice(2);
        const ann = {
            _uid:         uid,
            id:           generateAnnotationId(),
            pdfX:         x,
            pdfY:         y,
            pdfWidth:     nextWidthPts,
            pdfHeight:    nextHeightPts,
            text:         '',
            originalText: '',
            pageIndex:    pi,
            type:         'text',
            fontSize:     defaultFontSize,
            fontFamily:   'Helvetica',
            textColor:    '#000000',
            fontWeight:   '400',
            fontStyle:    'normal',
            underline:    false,
            textAlign:    'left',
            verticalAlign: 'top',
            backgroundColor: '#ffffff',
            opacity:      1,
            userCreated:        true,
            sourceSpans:        [],
            sourceLineBBoxes:   [],
            sourceTextLines:    [],
        };
        ann._originalBox = { x, y, w: nextWidthPts, h: nextHeightPts };
        ann._originalPdfBox = { x, y, w: nextWidthPts, h: nextHeightPts };

        pushUndo();
        editedTexts[uid] = '';
        data.annotations.push(ann);
        redrawOverlay(pi);
        selectAnnotation(ann, pi);

        // Focus editor immediately so the user can start typing
        const ae = document.getElementById('ae-' + (pi + 1));
        if (ae) requestAnimationFrame(() => ae.focus({ preventScroll: true }));

        markDirty();
    }

    // ── Page building ─────────────────────────────────────────────────────────
    const scaleObs = new ResizeObserver(entries => {
        for (const entry of entries) {
            const pageEl = entry.target;
            const pi   = Number(pageEl.dataset.pi);
            const data = pageData[pi];
            if (!data) continue;
            const newW = Math.round(entry.contentRect.width);
            const newH = Math.round(entry.contentRect.height);
            data.scale       = data.wPts > 0 ? newW / data.wPts : 1;
            data.canvasWidth  = newW;
            data.canvasHeight = newH;
            updatePageCardWidth(pi, newW);
            const oc = document.getElementById('oc-' + (pi + 1));
            const ac = document.getElementById('ac-' + (pi + 1));
            if (oc) { oc.width = newW; oc.height = newH; }
            if (ac) {
                ac.style.width = `${newW}px`;
                ac.style.height = `${newH}px`;
            }
            redrawOverlay(pi);
            drawAcroOverlay(pi);
            if (activeState.pi === pi) syncActiveEditor();
        }
    });

    function createPageCard(pg, total) {
        const card = document.createElement('div');
        card.className = 'page-card';
        card.id = `card-${pg}`;
        card.dataset.pageNumber = String(pg);
        card.innerHTML =
            `<div class="page-label"><span>Page ${pg} of ${total}</span></div>` +
            `<div class="page-canvas-wrap"><div class="page-content" id="pc-${pg}">` +
                `<img id="en-img-${pg}" alt="Page ${pg}">` +
                `<div id="ac-${pg}" class="acro-layer"></div>` +
                `<canvas id="oc-${pg}" class="overlay-canvas"></canvas>` +
                `<div id="rhl-${pg}" class="rich-html-layer"></div>` +
                `<div id="ae-${pg}" class="active-editor" contenteditable="true" spellcheck="false"></div>` +
                `<div id="tm-${pg}" class="annotation-tbc-menu">` +
                    `<button id="mh-${pg}" type="button" class="tbc-menu-btn" title="Move" aria-label="Move">` +
                        `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 9l-3 3 3 3"/><path d="M9 5l3-3 3 3"/><path d="M15 19l-3 3-3-3"/><path d="M19 9l3 3-3 3"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="12" y1="2" x2="12" y2="22"/></svg>` +
                    `</button>` +
                    `<button id="eh-${pg}" type="button" class="tbc-menu-btn tbc-ok" title="Edit text" aria-label="Edit text">` +
                        `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>` +
                    `</button>` +
                    `<div class="tbc-menu-divider"></div>` +
                    `<button id="uh-${pg}" type="button" class="tbc-menu-btn" title="UPPERCASE" aria-label="Uppercase"><span style="font-weight:700;font-size:12px;">Tt</span></button>` +
                    `<button id="lh-${pg}" type="button" class="tbc-menu-btn" title="lowercase" aria-label="Lowercase"><span style="font-size:12px;">tl</span></button>` +
                    `<div class="tbc-menu-divider"></div>` +
                    `<button id="ch-${pg}" type="button" class="tbc-menu-btn" title="Copy text" aria-label="Copy text">` +
                        `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>` +
                    `</button>` +
                    `<button id="dh-${pg}" type="button" class="tbc-menu-btn tbc-delete" title="Delete" aria-label="Delete">` +
                        `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>` +
                    `</button>` +
                `</div>` +
                `<button id="rh-${pg}-nw" type="button" class="resize-handle" data-dir="nw" aria-label="Resize northwest"></button>` +
                `<button id="rh-${pg}-ne" type="button" class="resize-handle" data-dir="ne" aria-label="Resize northeast"></button>` +
                `<button id="rh-${pg}-sw" type="button" class="resize-handle" data-dir="sw" aria-label="Resize southwest"></button>` +
                `<button id="rh-${pg}-se" type="button" class="resize-handle" data-dir="se" aria-label="Resize southeast"></button>` +
            `</div></div>`;
        wrap.appendChild(card);
        return card;
    }

    function setupPage(pg, wPts, hPts, annotations, acroWidgets = []) {
        const pi     = pg - 1;
        const pageEl = document.getElementById('pc-' + pg);
        const oc     = document.getElementById('oc-' + pg);
        const ac     = document.getElementById('ac-' + pg);
        const ae     = document.getElementById('ae-' + pg);
        const tm     = document.getElementById('tm-' + pg);
        const mh     = document.getElementById('mh-' + pg);
        const eh     = document.getElementById('eh-' + pg);
        const uh     = document.getElementById('uh-' + pg);
        const lh     = document.getElementById('lh-' + pg);
        const ch     = document.getElementById('ch-' + pg);
        const dh     = document.getElementById('dh-' + pg);
        const rhs    = ['nw', 'ne', 'sw', 'se'].map((dir) => document.getElementById(`rh-${pg}-${dir}`));
        if (!pageEl || !oc || !ac || !ae || !tm || !mh || !eh || !uh || !lh || !ch || !dh || rhs.some((handle) => !handle)) return;

        const fitScale  = getFitScaleForWidth(wPts);
        const initScale = fitScale * (currentZoomPercent / 100);
        const initW     = Math.round(wPts * initScale);
        const initH     = Math.round(hPts * initScale);

        pageData[pi] = { wPts, hPts, fitScale, scale: initScale, canvasWidth: initW, canvasHeight: initH, annotations, acroWidgets };

        pageEl.style.width  = `calc(${wPts}px * var(--scale, 1))`;
        pageEl.style.height = `calc(${hPts}px * var(--scale, 1))`;
        pageEl.style.setProperty('--scale', initScale);
        pageEl.dataset.pi = String(pi);
        updatePageCardWidth(pi, initW);
        scaleObs.observe(pageEl);

        oc.width  = initW;
        oc.height = initH;
        ac.style.width = `${initW}px`;
        ac.style.height = `${initH}px`;

        const applyEditorTextTransform = (transformer) => {
            if (!editModeEnabled && !addTextMode) return;
            const ann = pageData[pi]?.annotations.find((item) => item._uid === activeState.uid);
            if (!ann || typeof transformer !== 'function') return;
            const currentText = editedTexts[ann._uid] ?? String(ann.text ?? '');
            const nextText = transformer(currentText);
            if (nextText === currentText) return;
            pushUndo();
            editedTexts[ann._uid] = nextText;
            resizeAnnotationForEditedText(ann, nextText, pi);
            ae.innerHTML = renderPlainEditorHTML(ann, nextText, pageData[pi].scale);
            syncActiveEditor();
            requestAnimationFrame(() => ae.focus({ preventScroll: true }));
            markDirty();
            redrawOverlay(pi);
        };

        const startTextCreation = (event) => {
            if (!addTextMode || dragState.active || resizeState.active) return;
            // If the pointer lands on a user-created annotation, select it instead
            // of starting a new text-creation drag.
            const _rect = oc.getBoundingClientRect();
            const _pt = {
                x: (event.clientX - _rect.left) * (oc.width / Math.max(_rect.width, 1)),
                y: (event.clientY - _rect.top)  * (oc.height / Math.max(_rect.height, 1)),
            };
            const _data = pageData[pi];
            const _hit = _data ? findAnnotationAt(_pt.x, _pt.y, _data.annotations, _data.scale, _data.canvasHeight) : null;
            if (_hit?.userCreated) {
                event.preventDefault();
                if (activeState.uid && activeState.uid !== _hit._uid) {
                    const activeEditor = activeState.pi !== null ? document.getElementById('ae-' + (activeState.pi + 1)) : null;
                    if (activeEditor && document.activeElement === activeEditor) activeEditor.blur();
                }
                selectAnnotation(_hit, pi);
                return;
            }
            event.preventDefault();
            if (activeState.uid) {
                const activeEditor = activeState.pi !== null ? document.getElementById('ae-' + (activeState.pi + 1)) : null;
                if (activeEditor && document.activeElement === activeEditor) activeEditor.blur();
                clearActiveAnnotation();
            }
            const rect = oc.getBoundingClientRect();
            const startX = event.clientX - rect.left;
            const startY = event.clientY - rect.top;
            const previewEl = document.createElement('div');
            previewEl.className = 'text-drag-selection';
            previewEl.style.left = `${startX}px`;
            previewEl.style.top = `${startY}px`;
            previewEl.style.width = '0px';
            previewEl.style.height = '0px';
            pageEl.appendChild(previewEl);
            textCreationState = {
                active: true,
                pi,
                pointerId: event.pointerId,
                startX,
                startY,
                rect,
                previewEl,
                moved: false,
            };
            if (typeof oc.setPointerCapture === 'function') {
                oc.setPointerCapture(event.pointerId);
            }
        };

        const updateTextCreation = (event) => {
            if (!textCreationState.active || textCreationState.pi !== pi || textCreationState.pointerId !== event.pointerId) return;
            const currentX = event.clientX - textCreationState.rect.left;
            const currentY = event.clientY - textCreationState.rect.top;
            const left = Math.min(textCreationState.startX, currentX);
            const top = Math.min(textCreationState.startY, currentY);
            const width = Math.abs(currentX - textCreationState.startX);
            const height = Math.abs(currentY - textCreationState.startY);
            textCreationState.previewEl.style.left = `${left}px`;
            textCreationState.previewEl.style.top = `${top}px`;
            textCreationState.previewEl.style.width = `${width}px`;
            textCreationState.previewEl.style.height = `${height}px`;
            if (width > 5 || height > 5) textCreationState.moved = true;
        };

        const finishTextCreation = (event) => {
            if (!textCreationState.active || textCreationState.pi !== pi || textCreationState.pointerId !== event.pointerId) return false;
            const state = { ...textCreationState };
            cancelTextCreationPreview();
            if (typeof oc.releasePointerCapture === 'function' && oc.hasPointerCapture?.(event.pointerId)) {
                oc.releasePointerCapture(event.pointerId);
            }
            const currentX = event.clientX - state.rect.left;
            const currentY = event.clientY - state.rect.top;
            const left = Math.min(state.startX, currentX);
            const top = Math.min(state.startY, currentY);
            const width = Math.abs(currentX - state.startX);
            const height = Math.abs(currentY - state.startY);
            const scaleX = oc.width / Math.max(state.rect.width, 1);
            const scaleY = oc.height / Math.max(state.rect.height, 1);
            const useDraggedBox = state.moved && width > 20 && height > 10;
            createNewTextAnnotation(
                left * scaleX,
                top * scaleY,
                pi,
                useDraggedBox ? (width * scaleX) : null,
                useDraggedBox ? (height * scaleY) : null
            );
            return true;
        };

        // ── Canvas events ──
        oc.addEventListener('pointerdown', (e) => {
            if (addTextMode) {
                startTextCreation(e);
            }
        });

        oc.addEventListener('pointermove', (e) => {
            if (textCreationState.active) {
                updateTextCreation(e);
            }
        });

        oc.addEventListener('pointerup', (e) => {
            if (finishTextCreation(e)) {
                e.preventDefault();
            }
        });

        oc.addEventListener('pointercancel', (e) => {
            if (textCreationState.active && textCreationState.pi === pi && textCreationState.pointerId === e.pointerId) {
                cancelTextCreationPreview();
            }
        });

        oc.addEventListener('mousemove', (e) => {
            if (textCreationState.active) return;
            if (dragState.active || resizeState.active) return;
            if (!editModeEnabled && !addTextMode) {
                if (hoverState.pi === pi) {
                    hoverState = { pi: null, uid: null };
                    redrawOverlay(pi);
                }
                oc.style.cursor = 'default';
                return;
            }
            const data = pageData[pi];
            const pt   = canvasPointFromEvent(e, oc);
            if (!pt) return;
            const ann = findAnnotationAt(pt.x, pt.y, data.annotations, data.scale, data.canvasHeight);
            if (addTextMode) {
                // Show a text cursor over user-created annotations; crosshair everywhere else.
                const nextUid = ann?.userCreated ? ann._uid : null;
                if (nextUid !== hoverState.uid || pi !== hoverState.pi) {
                    hoverState = { pi, uid: nextUid };
                    redrawOverlay(pi);
                }
                oc.style.cursor = ann?.userCreated ? 'text' : 'crosshair';
                return;
            }
            const nextUid = ann?._uid || null;
            if (nextUid === hoverState.uid && pi === hoverState.pi) return;
            hoverState  = { pi, uid: nextUid };
            oc.style.cursor = nextUid ? 'text' : 'default';
            redrawOverlay(pi);
        });

        oc.addEventListener('mouseleave', () => {
            if (!editModeEnabled && !addTextMode) {
                oc.style.cursor = 'default';
                return;
            }
            if (hoverState.pi !== pi) return;
            hoverState  = { pi: null, uid: null };
            oc.style.cursor = addTextMode ? 'crosshair' : 'default';
            redrawOverlay(pi);
        });

        oc.addEventListener('click', (e) => {
            if (dragState.active || resizeState.active) return;
            if (!editModeEnabled && !addTextMode) return;
            if (addTextMode) {
                // Allow clicking a user-created annotation to select it.
                // (Actual creation is driven by pointerdown/up; this handles
                // the synthetic click that fires after a short tap.)
                const data = pageData[pi];
                const pt   = canvasPointFromEvent(e, oc);
                if (!pt) return;
                const ann = findAnnotationAt(pt.x, pt.y, data.annotations, data.scale, data.canvasHeight);
                if (ann?.userCreated && activeState.uid !== ann._uid) selectAnnotation(ann, pi);
                return;
            }
            const data = pageData[pi];
            const pt   = canvasPointFromEvent(e, oc);
            if (!pt) return;
            const ann = findAnnotationAt(pt.x, pt.y, data.annotations, data.scale, data.canvasHeight);
            if (!ann) {
                // Blur the editor first so the blur handler can save before clearing state
                const aeEl = document.getElementById('ae-' + (pi + 1));
                if (aeEl && document.activeElement === aeEl) { aeEl.blur(); return; }
                clearActiveAnnotation();
                return;
            }
            selectAnnotation(ann, pi);
        });

        // ── Editor events ──
        let _textUndoPending = false;
        ae.addEventListener('focus', () => {
            if (!editModeEnabled && !addTextMode) { ae.blur(); return; }
            _textUndoPending = true;
        });

        ae.addEventListener('input', (e) => {
            if (!editModeEnabled && !addTextMode) return;
            // Push undo snapshot once — before the first character change in this focus session.
            if (_textUndoPending) { _textUndoPending = false; pushUndo(); }
            const data = pageData[pi];
            const ann  = data?.annotations.find(a => a._uid === activeState.uid);
            if (!ann) return;
            const nextText = getEditorPlainText(e.currentTarget);
            editedTexts[ann._uid] = nextText;
            resizeAnnotationForEditedText(ann, nextText, pi);
            if (ann._richHtml) {
                // Preserve per-selection formatting: don't flatten innerHTML on typing.
                // Just capture the updated HTML and keep the caret where the browser left it.
                ann._richHtml = e.currentTarget.innerHTML;
            } else {
                const selection = getEditorSelectionOffsets(e.currentTarget);
                e.currentTarget.innerHTML = renderPlainEditorHTML(ann, nextText, data.scale);
                if (selection) {
                    setEditorSelectionOffsets(e.currentTarget, selection.start, selection.end);
                }
            }
            markDirty();
            syncActiveEditor();
            redrawOverlay(pi);  // keep canvas in sync for when annotation is deselected
        });

        ae.addEventListener('blur', (e) => {
            if (!editModeEnabled && !addTextMode) return;
            const data = pageData[pi];
            const ann  = data?.annotations.find(a => a._uid === activeState.uid);
            if (!ann) return;
            // Auto-remove new annotations that are still empty — matches edit view behaviour
            // where clicking off a blank text-box-creator discards it.
            const isNewAnn = typeof ann._uid === 'string' && ann._uid.startsWith('new_');
            const annText  = String(editedTexts[ann._uid] ?? ann.text ?? '').trim();
            if (isNewAnn && !annText) {
                data.annotations = data.annotations.filter(a => a._uid !== ann._uid);
                delete editedTexts[ann._uid];
                // Pop the undo snapshot that was pushed when this annotation was created
                // so Ctrl+Z doesn't restore an invisible blank annotation.
                if (undoStack.length > 0) undoStack.pop();
                updateHistoryUi();
                activeState = { pi: null, uid: null };
                syncActiveEditor();
                redrawOverlay(pi);
                return;
            }
            if (isNewAnn) {
                // Stay in addTextMode with the new annotation remaining selected.
                // Do NOT call setEditModeEnabled(true) — that would show all extracted
                // fields as editable, which is not wanted here.
                // activeState still points at this annotation; just redraw to show the
                // selection outline.
                redrawOverlay(pi);
            } else {
                // For existing annotations: clear active state so the canvas redraws with text.
                // Exception: don't clear if focus moved into the format bar (e.g. color picker),
                // otherwise the bar disappears before the property change can fire.
                // Also check a recent mousedown timestamp on the bar, because a native color-picker
                // dialog can steal document focus away from the input element itself.
                const clearUid = ann._uid;
                requestAnimationFrame(() => {
                    if (activeState.uid === clearUid) {
                        const focused = document.activeElement;
                        const recentFormatBarClick = (Date.now() - _formatBarMousedownTs) < 600;
                        if (recentFormatBarClick || (focused && annFormatBar && annFormatBar.contains(focused))) return;
                        clearActiveAnnotation();
                    }
                });
            }
        });

        ae.addEventListener('keydown', (e) => {
            if (!editModeEnabled && !addTextMode) return;
            // Blur (don't clearActiveAnnotation directly) so the blur handler saves first
            if (e.key === 'Escape') { e.preventDefault(); ae.blur(); return; }
            // Let the global Ctrl+Z/Y handler take care of undo/redo instead of browser default.
            if ((e.ctrlKey || e.metaKey) && (e.key === 'z' || e.key === 'y')) {
                e.preventDefault(); return;
            }
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (_textUndoPending) { _textUndoPending = false; pushUndo(); }
                const data = pageData[pi];
                const ann = data?.annotations.find(a => a._uid === activeState.uid);
                if (!ann) return;
                const selection = getEditorSelectionOffsets(ae);
                if (!selection) return;
                const currentText = editedTexts[ann._uid] ?? String(ann.text ?? '');
                const nextText = currentText.slice(0, selection.start) + '\n' + currentText.slice(selection.end);
                editedTexts[ann._uid] = nextText;
                resizeAnnotationForEditedText(ann, nextText, pi);
                ae.innerHTML = renderPlainEditorHTML(ann, nextText, data.scale);
                syncActiveEditor();
                setEditorSelectionOffsets(ae, selection.start + 1, selection.start + 1);
                markDirty();
                redrawOverlay(pi);
            }
            if (e.key === 'Backspace' || e.key === 'Delete') {
                e.preventDefault();
                if (_textUndoPending) { _textUndoPending = false; pushUndo(); }
                const data = pageData[pi];
                const ann = data?.annotations.find(a => a._uid === activeState.uid);
                if (!ann) return;
                const selection = getEditorSelectionOffsets(ae);
                if (!selection) return;
                const currentText = editedTexts[ann._uid] ?? String(ann.text ?? '');
                let start = selection.start;
                let end = selection.end;

                if (start === end) {
                    if (e.key === 'Backspace') {
                        if (start === 0) return;
                        start -= 1;
                    } else {
                        if (end >= currentText.length) return;
                        end += 1;
                    }
                }

                const nextText = currentText.slice(0, start) + currentText.slice(end);
                editedTexts[ann._uid] = nextText;
                resizeAnnotationForEditedText(ann, nextText, pi);
                ae.innerHTML = renderPlainEditorHTML(ann, nextText, data.scale);
                syncActiveEditor();
                setEditorSelectionOffsets(ae, start, start);
                markDirty();
                redrawOverlay(pi);
            }
        });

        // Stop overlay canvas from receiving events that land on the editor
        ae.addEventListener('pointerdown', (e) => e.stopPropagation());
        ae.addEventListener('click',       (e) => e.stopPropagation());

        [tm, eh, uh, lh, ch, dh].forEach((element) => {
            element.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();
            });
            element.addEventListener('click', (e) => e.stopPropagation());
        });

        // ── Floating menu ──
        mh.addEventListener('mousedown', (e) => {
            e.preventDefault(); e.stopPropagation();
            beginDrag(e, pi);
        });

        eh.addEventListener('click', () => {
            requestAnimationFrame(() => ae.focus({ preventScroll: true }));
        });

        uh.addEventListener('click', () => {
            applyEditorTextTransform((text) => String(text || '').toUpperCase());
        });

        lh.addEventListener('click', () => {
            applyEditorTextTransform((text) => String(text || '').toLowerCase());
        });

        ch.addEventListener('click', () => {
            const ann = pageData[pi]?.annotations.find((item) => item._uid === activeState.uid);
            if (!ann) return;
            const currentText = String(editedTexts[ann._uid] ?? ann.text ?? '').trim();
            if (!currentText) return;
            navigator.clipboard.writeText(currentText).catch(() => {});
        });

        rhs.forEach((handle) => {
            handle.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                beginResize(e, pi, handle.dataset.dir || 'se');
            });
        });

        dh.addEventListener('click', (e) => {
            e.preventDefault(); e.stopPropagation();
            const ann = pageData[pi]?.annotations.find(a => a._uid === activeState.uid);
            if (!ann) return;
            deleteAnnotation(ann, pi);
        });
    }

    if (saveButton) {
        saveButton.addEventListener('click', () => {
            saveAllChanges();
        });
    }
    if (undoButton) undoButton.addEventListener('click', () => performUndo());
    if (redoButton) redoButton.addEventListener('click', () => performRedo());
    if (editModeToggle) {
        editModeToggle.addEventListener('click', () => {
            setEditModeEnabled(!editModeEnabled);
        });
    }
    if (ftbEditMode) {
        ftbEditMode.addEventListener('click', () => {
            setEditModeEnabled(!editModeEnabled);
        });
    }
    if (addTextBtn) {
        addTextBtn.addEventListener('click', () => {
            setAddTextMode(!addTextMode);
            if (addTextMode) clearActiveAnnotation();
        });
    }
    if (ftbAddText) {
        ftbAddText.addEventListener('click', () => {
            if (editModeEnabled) return;
            setAddTextMode(!addTextMode);
            if (addTextMode) clearActiveAnnotation();
        });
    }
    if (downloadPdfButton) {
        downloadPdfButton.addEventListener('click', () => {
            downloadReadyPdf();
        });
    }
    if (zoomOutBtn) {
        zoomOutBtn.addEventListener('click', () => updateZoom(currentZoomPercent - ZOOM_STEP_PERCENT));
    }
    if (zoomInBtn) {
        zoomInBtn.addEventListener('click', () => updateZoom(currentZoomPercent + ZOOM_STEP_PERCENT));
    }
    if (pageJumpInput) {
        pageJumpInput.addEventListener('change', () => {
            const value = parseInt(pageJumpInput.value || '1', 10);
            if (Number.isFinite(value)) scrollToPage(value);
        });
        pageJumpInput.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') return;
            const value = parseInt(pageJumpInput.value || '1', 10);
            if (Number.isFinite(value)) scrollToPage(value);
        });
    }
    if (pagePrevBtn) {
        pagePrevBtn.addEventListener('click', () => {
            const current = parseInt(pageJumpInput?.value || '1', 10) || 1;
            scrollToPage(current - 1);
        });
    }
    if (pageNextBtn) {
        pageNextBtn.addEventListener('click', () => {
            const current = parseInt(pageJumpInput?.value || '1', 10) || 1;
            scrollToPage(current + 1);
        });
    }
    window.addEventListener('scroll', syncCurrentPageFromScroll, { passive: true });
    window.addEventListener('resize', () => {
        applyAllPageScales();
        syncCurrentPageFromScroll();
    });

    // ── Text Format Bar event listeners ───────────────────────────────────────
    // Track pointer-down on the format bar so the ae blur handler doesn't clear
    // the active annotation when the user clicks a color picker or other control.
    let _formatBarMousedownTs = 0;
    if (annFormatBar) {
        annFormatBar.addEventListener('mousedown', (e) => {
            _formatBarMousedownTs = Date.now();
            // Prevent the format-bar button/control from stealing focus away from
            // the active editor. This preserves the user's selection so per-selection
            // commands (bold/italic/underline/color on a highlighted word) can run
            // via document.execCommand without collapsing the range. Inputs that need
            // to receive focus (text colour picker, select dropdowns, the size slider)
            // are excluded so they can still be interacted with.
            const target = e.target;
            if (!(target instanceof HTMLElement)) return;
            const tag = target.tagName;
            const isFocusableControl = tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA'
                || target.isContentEditable
                || (target.closest && target.closest('input, select, textarea, [contenteditable=""], [contenteditable="true"]'));
            if (!isFocusableControl) e.preventDefault();
        });
    }

    function getActiveAnnAndPage() {
        const { pi, uid } = activeState;
        const data = pi !== null ? pageData[pi] : null;
        const ann  = data ? data.annotations.find(a => a._uid === uid) : null;
        return ann ? { ann, pi, data } : null;
    }

    // Return the active editor element and the current selection range if
    // (a) the selection lies fully inside the editor AND (b) the selection
    // is not collapsed — i.e. the user actually has characters highlighted.
    // Used to route format-bar actions to per-selection formatting via
    // document.execCommand, instead of setting annotation-level properties.
    function getActiveEditorSelection() {
        const active = getActiveAnnAndPage();
        if (!active) return null;
        const ae = document.getElementById('ae-' + (active.pi + 1));
        if (!(ae instanceof HTMLElement) || ae.style.display === 'none') return null;
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0 || selection.isCollapsed) return null;
        const range = selection.getRangeAt(0);
        if (!ae.contains(range.startContainer) || !ae.contains(range.endContainer)) return null;
        return { ae, range, selection, active };
    }

    // Persist the editor's current innerHTML on the annotation so subsequent
    // syncActiveEditor calls (triggered by redraw, resize etc.) don't flatten
    // per-selection formatting back to annotation-level uniform styling.
    function persistRichEditorHtml(active, ae) {
        active.ann._richHtml = ae.innerHTML;
        active.ann._styleDirty = true;
        markDirty();
    }

    function applySelectionFormat(command, valueArg) {
        const info = getActiveEditorSelection();
        if (!info) return false;
        // Ensure the editor has focus so execCommand targets our selection.
        if (document.activeElement !== info.ae) {
            info.ae.focus({ preventScroll: true });
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(info.range);
        }
        pushUndo();
        const beforeHtml = info.ae.innerHTML;
        document.execCommand('styleWithCSS', false, true);
        document.execCommand(command, false, valueArg);
        // Chromium silently no-ops execCommand('bold'/'italic'/'underline') when the
        // contenteditable's ancestor has explicit inline font-weight / font-style /
        // text-decoration (as our `<div data-line-index=0 style="font-weight:400;...">`
        // wrapper does). Fall back to manual wrapping so the three toolbar commands
        // always produce a CSS span around the selection.
        if (info.ae.innerHTML === beforeHtml) {
            manualWrapSelection(info.ae, info.range, command, valueArg);
        }
        persistRichEditorHtml(info.active, info.ae);
        redrawOverlay(info.active.pi);
        return true;
    }

    // Wraps the current selection with a <span> that applies the equivalent inline
    // style for the given execCommand. Used as a fallback when Chromium refuses to
    // modify the DOM (happens when the editor's wrapper has explicit font-weight
    // etc. declarations).
    function manualWrapSelection(ae, fallbackRange, command, valueArg) {
        const sel = window.getSelection();
        let range = null;
        if (sel && sel.rangeCount > 0) range = sel.getRangeAt(0);
        if (!range || range.collapsed) range = fallbackRange;
        if (!range || range.collapsed) return;
        if (!ae.contains(range.startContainer) || !ae.contains(range.endContainer)) return;

        const cssDecl = (() => {
            switch (command) {
                case 'bold':      return 'font-weight: bold';
                case 'italic':    return 'font-style: italic';
                case 'underline': return 'text-decoration: underline';
                case 'foreColor': return `color: ${valueArg || '#000000'}`;
                default:          return null;
            }
        })();
        if (!cssDecl) return;

        try {
            const contents = range.extractContents();
            const span = document.createElement('span');
            span.setAttribute('style', cssDecl);
            span.appendChild(contents);
            range.insertNode(span);
            // Restore selection across the freshly-wrapped span.
            const newRange = document.createRange();
            newRange.selectNodeContents(span);
            sel.removeAllRanges();
            sel.addRange(newRange);
        } catch (_error) {
            // Selection spanned non-wrappable structure (eg. adjacent block boundaries).
            // Give up silently — the undo snapshot will let the user retry.
        }
    }

    function applyFormatProperty(update) {
        const active = getActiveAnnAndPage();
        if (!active) return;
        const { ann, pi } = active;
        pushUndo();
        update(ann, pi);
        ann._styleDirty = true;
        redrawOverlay(pi);
        syncActiveEditor(true);
        markDirty();
    }

    if (afbFont) {
        afbFont.addEventListener('change', () => {
            applyFormatProperty(ann => { ann.fontFamily = afbFont.value; });
        });
    }
    if (afbSize) {
        // Live label update while dragging (no annotation write)
        afbSize.addEventListener('input', () => {
            const pt = Number(afbSize.value) || 12;
            if (afbSizeValue) afbSizeValue.textContent = pt + 'pt';
        });
        // Commit when slider is released
        afbSize.addEventListener('change', () => {
            const pt = Number(afbSize.value) || 12;
            if (afbSizeValue) afbSizeValue.textContent = pt + 'pt';
            applyFormatProperty((ann, pi) => {
                ann.fontSize = pt;
                const currentText = String(editedTexts[ann._uid] ?? ann.text ?? '');
                resizeAnnotationForEditedText(ann, currentText, pi);
            });
        });
    }
    if (afbTextColor) {
        // 'input' fires live while dragging in picker; 'change' fires on close and pushes undo
        afbTextColor.addEventListener('input', () => {
            // If there is a highlighted selection, recolor just that selection live.
            if (applySelectionFormat('foreColor', afbTextColor.value)) return;
            const active = getActiveAnnAndPage();
            if (!active) return;
            active.ann.textColor = afbTextColor.value;
            redrawOverlay(active.pi);
            syncActiveEditor(true);
        });
        afbTextColor.addEventListener('change', () => {
            if (applySelectionFormat('foreColor', afbTextColor.value)) return;
            applyFormatProperty(ann => { ann.textColor = afbTextColor.value; });
        });
    }
    if (afbBgColor) {
        afbBgColor.addEventListener('input', () => {
            const active = getActiveAnnAndPage();
            if (!active) return;
            active.ann.backgroundColor = afbBgColor.value;
            redrawOverlay(active.pi);
            syncActiveEditor(true);
        });
        afbBgColor.addEventListener('change', () => {
            applyFormatProperty(ann => { ann.backgroundColor = afbBgColor.value; });
        });
    }
    if (afbOpacity) {
        afbOpacity.addEventListener('change', () => {
            applyFormatProperty(ann => { ann.opacity = parseFloat(afbOpacity.value); });
        });
    }
    if (afbBold) {
        afbBold.addEventListener('click', () => {
            if (applySelectionFormat('bold')) return;
            const active = getActiveAnnAndPage();
            if (!active) return;
            const isBold = String(active.ann.fontWeight || '400') === '700';
            pushUndo();
            active.ann.fontWeight = isBold ? '400' : '700';
            active.ann._styleDirty = true;
            afbBold.setAttribute('aria-pressed', String(!isBold));
            afbBold.classList.toggle('is-active', !isBold);
            redrawOverlay(active.pi);
            syncActiveEditor(true);
            markDirty();
        });
    }
    if (afbItalic) {
        afbItalic.addEventListener('click', () => {
            if (applySelectionFormat('italic')) return;
            const active = getActiveAnnAndPage();
            if (!active) return;
            const isItalic = (active.ann.fontStyle || 'normal') === 'italic';
            pushUndo();
            active.ann.fontStyle = isItalic ? 'normal' : 'italic';
            active.ann._styleDirty = true;
            afbItalic.setAttribute('aria-pressed', String(!isItalic));
            afbItalic.classList.toggle('is-active', !isItalic);
            redrawOverlay(active.pi);
            syncActiveEditor(true);
            markDirty();
        });
    }
    if (afbUnderline) {
        afbUnderline.addEventListener('click', () => {
            if (applySelectionFormat('underline')) return;
            const active = getActiveAnnAndPage();
            if (!active) return;
            const isUnderline = Boolean(active.ann.underline);
            pushUndo();
            active.ann.underline = !isUnderline;
            active.ann._styleDirty = true;
            afbUnderline.setAttribute('aria-pressed', String(!isUnderline));
            afbUnderline.classList.toggle('is-active', !isUnderline);
            redrawOverlay(active.pi);
            syncActiveEditor(true);
            markDirty();
        });
    }
    if (afbAlign) {
        afbAlign.addEventListener('change', () => {
            applyFormatProperty(ann => { ann.textAlign = afbAlign.value; });
        });
    }
    if (afbValign) {
        afbValign.addEventListener('change', () => {
            applyFormatProperty(ann => { ann.verticalAlign = afbValign.value; });
        });
    }
    if (afbCopy) {
        afbCopy.addEventListener('click', () => {
            const active = getActiveAnnAndPage();
            if (!active) return;
            const { ann, pi, data } = active;
            pushUndo();
            const OFFSET = 12; // pts
            const newAnn = {
                ...ann,
                _uid: 'new_' + Date.now() + '_' + Math.random().toString(36).slice(2),
                id:   generateAnnotationId(),
                pdfX: Math.min(ann.pdfX + OFFSET, Math.max(0, data.wPts - (ann.pdfWidth || 60))),
                pdfY: Math.max(ann.pdfY - OFFSET, 0),
                userCreated: true,
            };
            newAnn._originalBox = { x: newAnn.pdfX, y: newAnn.pdfY, w: newAnn.pdfWidth, h: newAnn.pdfHeight };
            newAnn._originalPdfBox = { x: newAnn.pdfX, y: newAnn.pdfY, w: newAnn.pdfWidth, h: newAnn.pdfHeight };
            editedTexts[newAnn._uid] = String(editedTexts[ann._uid] ?? ann.text ?? '');
            data.annotations.push(newAnn);
            selectAnnotation(newAnn, pi);
            markDirty();
        });
    }
    if (afbDelete) {
        afbDelete.addEventListener('click', () => {
            const active = getActiveAnnAndPage();
            if (!active) return;
            deleteAnnotation(active.ann, active.pi);
        });
    }

    // ── Main ──────────────────────────────────────────────────────────────────
    async function run() {
        let data;
        updateEditModeUi();
        updateZoom(currentZoomPercent);
        try {
            const resp = await fetch(INFO_URL_OBJ.toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            data = await resp.json();
            if (!data.success) throw new Error(data.message || 'Failed to load document info.');
        } catch (e) { showError(String(e)); return; }

        const embeddedFontsBySource = data.embedded_fonts_by_source && typeof data.embedded_fonts_by_source === 'object'
            ? data.embedded_fonts_by_source
            : null;
        const preferredEmbeddedFonts = (embeddedFontsBySource?.clean && typeof embeddedFontsBySource.clean === 'object')
            ? embeddedFontsBySource.clean
            : (embeddedFontsBySource?.file && typeof embeddedFontsBySource.file === 'object')
                ? embeddedFontsBySource.file
                : ((data.embedded_fonts && typeof data.embedded_fonts === 'object') ? data.embedded_fonts : null);
        loadEmbeddedFontFaces(preferredEmbeddedFonts);

        acroFormEntries = Array.isArray(data.acro_form_entries) ? data.acro_form_entries : [];
        acroFieldLookup = buildAcroFieldLookup(acroFormEntries);
        acroWidgetsByPage = {};

        // Resolve the span-origin-based box (sourceBlockLeft/Top) as the "original" reference
        // so annotationOffset() correctly reflects the drag displacement even after a save+reload,
        // when pdfX/pdfY already contain the moved position.
        const resolveSourceBox = (ann) => {
            const sL = Number(ann.sourceBlockLeft), sT = Number(ann.sourceBlockTop);
            const sW = Number(ann.sourceBlockWidth), sH = Number(ann.sourceBlockHeight);
            const pageH = Number(ann.sourcePageHeight);
            if ([sL, sT, sW, sH, pageH].every(Number.isFinite) && sW > 0 && sH > 0)
                return { x: sL, y: pageH - (sT + sH), w: sW, h: sH };
            return resolveAnnBox(ann);
        };

        const rawAnns = (data.annotations || []).filter(a => a && a.db_state !== 'deleted');
        const allAnnotations = rawAnns.map((ann, i) => {
            const hydrated = { ...ann, _uid: String(ann.id || ann.db_id || '') + '_' + i };
            hydrated._originalBox = resolveSourceBox(hydrated) || resolveAnnBox(hydrated) || null;
            // Pick the "original" PDF box used by annotationDimensionsChanged:
            //  - If the stored pdfW/H matches the sourceBlock dims within a few pts,
            //    the annotation was never resized — use the stored box so
            //    dimensionsChanged stays false (avoids false-positive forcing the
            //    plain-text editor render for promoted annotations).
            //  - Otherwise the user resized it in a prior session — use the sourceBlock
            //    box so dimensionsChanged returns true and drawEditedAnnotation takes
            //    the resized render path instead of falling back to drawOriginalSource
            //    (which would ignore the saved pdfWidth/pdfHeight and re-render the
            //    original wide PDF layout).
            const storedBox = resolveAnnBox(hydrated);
            const sourceBox = resolveSourceBox(hydrated);
            const TOL = 2;
            const roughlyEqual = storedBox && sourceBox
                && Math.abs(storedBox.w - sourceBox.w) <= TOL
                && Math.abs(storedBox.h - sourceBox.h) <= TOL;
            hydrated._originalPdfBox = roughlyEqual ? storedBox : (sourceBox || storedBox || null);
            return hydrated;
        });
        allAnnotations.forEach(ann => { editedTexts[ann._uid] = String(ann.text || ''); });

        const byPage = {};
        allAnnotations.forEach(ann => {
            const pi = Number(ann.pageIndex) || 0;
            if (!byPage[pi]) byPage[pi] = [];
            if ((ann.type || 'text') === 'text') byPage[pi].push(ann);
        });

        try {
            if (typeof pdfjsLib === 'undefined') throw new Error('PDF.js not loaded.');
            _pdfDoc = await pdfjsLib.getDocument(data.document.clean_url).promise;
        } catch (e) { showError(String(e)); return; }

        const pageCount = _pdfDoc.numPages;
        for (let pg = 1; pg <= pageCount; pg++) createPageCard(pg, pageCount);
        updatePageControls(pageCount);

        const offscreen = document.createElement('canvas');

        for (let pg = 1; pg <= pageCount; pg++) {
            const pi  = pg - 1;
            const img = document.getElementById('en-img-' + pg);

            const pdfPage = await _pdfDoc.getPage(pg);
            const vp1     = pdfPage.getViewport({ scale: 1 });
            const wPts    = vp1.width;
            const hPts    = vp1.height;

            // Render background image at 2× for sharpness
            const renderScale = 2;
            offscreen.width  = Math.round(wPts * renderScale);
            offscreen.height = Math.round(hPts * renderScale);
            await pdfPage.render({
                canvasContext: offscreen.getContext('2d'),
                viewport: pdfPage.getViewport({ scale: renderScale }),
                annotationMode: typeof pdfjsLib?.AnnotationMode?.DISABLE === 'number'
                    ? pdfjsLib.AnnotationMode.DISABLE
                    : 0,
            }).promise.catch(() => {});
            await new Promise(res => { img.onload = res; img.onerror = res; img.src = offscreen.toDataURL('image/png'); });

            const acroWidgets = await ensureAcroWidgetsForPage(pg, data.document).catch(() => []);
            setupPage(pg, wPts, hPts, byPage[pi] || [], acroWidgets);
            redrawOverlay(pi);
            drawAcroOverlay(pi);
        }

        // CSS @font-face fonts are lazy-loaded — only triggered when first used.
        // Explicitly load each font face so canvas ctx.measureText() uses real metrics.
        // Then redraw all overlays so span scaleX calculations are accurate.
        if (preferredEmbeddedFonts && document.fonts?.load) {
            const fontLoadPromises = [];
            for (const [, fontData] of Object.entries(preferredEmbeddedFonts)) {
                const cleanName = String(fontData?.clean_name || '').trim();
                const family    = String(fontData?.family || '').trim();
                const weight    = String(fontData?.css_weight || '400');
                const style     = String(fontData?.css_style || 'normal');
                const sizePx    = '16px'; // arbitrary size — triggers download
                if (cleanName) fontLoadPromises.push(document.fonts.load(`${style} ${weight} ${sizePx} PDF_${cleanName}`).catch(() => {}));
                if (family && family !== cleanName) fontLoadPromises.push(document.fonts.load(`${style} ${weight} ${sizePx} PDF_${family}`).catch(() => {}));
            }
            if (fontLoadPromises.length) {
                try { await Promise.all(fontLoadPromises); } catch (_) {}
            }
            for (const pi of Object.keys(pageData)) redrawOverlay(Number(pi));
        }

        markClean();
        syncCurrentPageFromScroll();
    }

    run();
})();
</script>

</body>
</html>
