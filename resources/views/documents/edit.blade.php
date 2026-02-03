<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Edit PDF</title>
        
        <!-- Bootstrap 5.3.3 CSS -->
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
        
        <!-- Tailwind CSS -->
        <script src="https://cdn.tailwindcss.com"></script>
        
        <style>
            @font-face {
                font-family: 'Montserrat';
                src: url('/fonts/Montserrat-Thin.ttf') format('truetype');
                font-weight: 100;
                font-style: normal;
            }
            @font-face {
                font-family: 'Montserrat';
                src: url('/fonts/Montserrat-Bold.ttf') format('truetype');
                font-weight: 700;
                font-style: normal;
            }
            @font-face {
                font-family: 'Arimo';
                src: url('/fonts/Arimo-Regular.ttf') format('truetype');
                font-weight: 400;
                font-style: normal;
            }
            @font-face {
                font-family: 'Arimo';
                src: url('/fonts/Arimo-Bold.ttf') format('truetype');
                font-weight: 700;
                font-style: normal;
            }
            @font-face {
                font-family: 'Tinos';
                src: url('/fonts/Tinos-Regular.ttf') format('truetype');
                font-weight: 400;
                font-style: normal;
            }
            @font-face {
                font-family: 'Tinos';
                src: url('/fonts/Tinos-Bold.ttf') format('truetype');
                font-weight: 700;
                font-style: normal;
            }
            @font-face {
                font-family: 'Gelasio';
                src: url('/fonts/Gelasio-Regular.ttf') format('truetype');
                font-weight: 400;
                font-style: normal;
            }
            @font-face {
                font-family: 'Gelasio';
                src: url('/fonts/Gelasio-Bold.ttf') format('truetype');
                font-weight: 700;
                font-style: normal;
            }
            
            :root {
                color-scheme: light;
                --bg: #070b12;
                --panel: #111824;
                --ink: #e9f0ff;
                --muted: #93a4bf;
                --accent: #6ee7b7;
                --accent-dark: #2c8f6e;
                --danger: #ff6b6b;
            }
            body.light-theme {
                color-scheme: light;
                --bg: #f8f9fa;
                --panel: #ffffff;
                --ink: #1a202c;
                --muted: #4a5568;
                --accent: #10b981;
                --accent-dark: #047857;
                --danger: #ef4444;
            }
            body.light-theme {
                background: radial-gradient(circle at top, #e5e7eb, var(--bg));
            }
            body.light-theme .toolbar {
                background: rgba(255, 255, 255, 0.95);
                border-bottom: 1px solid rgba(0,0,0,0.08);
            }
            body.light-theme .toolbar select,
            body.light-theme .toolbar input[type="number"] {
                background: #f3f4f6;
                border: 1px solid rgba(0,0,0,0.12);
            }
            body.light-theme .mode-bar {
                background: rgba(255, 255, 255, 0.95);
                border-bottom: 1px solid rgba(0,0,0,0.08);
            }
            body.light-theme .mode-bar .divider {
                background: rgba(0,0,0,0.12);
            }
            body.light-theme .mode-bar button {
                background: #e5e7eb;
                color: var(--ink);
            }
            body.light-theme .mode-bar button.primary {
                background: var(--accent);
                color: #ffffff;
            }
            body.light-theme .toggle-switch {
                background: #e5e7eb;
                border: 1px solid rgba(0,0,0,0.12);
                color: var(--ink);
            }
            body.light-theme .toggle-switch .slider {
                background: rgba(0,0,0,0.12);
                border-color: rgba(0,0,0,0.2);
            }
            body.light-theme .toggle-switch input:checked + .slider {
                background: var(--accent);
                border-color: var(--accent);
            }
            body.light-theme .header-icon-btn {
                background: #e5e7eb;
                border-color: rgba(0,0,0,0.12);
                color: var(--ink);
            }
            * { box-sizing: border-box; }
            body {
                margin: 0;
                font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
                background: radial-gradient(circle at top, #152034, var(--bg));
                color: var(--ink);
                min-height: 100vh;
            }
            header {
                padding: 20px 24px;
                border-bottom: 1px solid rgba(255,255,255,0.08);
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
            }
            .header-title {
                display: inline-flex;
                align-items: center;
                gap: 12px;
                flex-wrap: wrap;
            }
            .header-history {
                display: inline-flex;
                align-items: center;
                gap: 6px;
            }
            .header-icon-btn {
                width: 32px;
                height: 32px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                background: var(--panel);
                border: 1px solid rgba(255,255,255,0.2);
                color: var(--ink);
                cursor: pointer;
                font-size: 16px;
                font-weight: 700;
                border-radius: 0;
            }
            .tab-nav {
                display: flex;
                gap: 8px;
                padding: 0 24px;
                background: var(--panel);
                border-bottom: 1px solid rgba(255,255,255,0.08);
            }
            .tab-nav button {
                padding: 12px 20px;
                background: transparent;
                border: none;
                color: var(--muted);
                cursor: pointer;
                font-weight: 600;
                border-bottom: 3px solid transparent;
                transition: all 0.2s;
            }
            .tab-nav button:hover {
                color: var(--ink);
                background: rgba(255,255,255,0.05);
            }
            .tab-nav button.active {
                color: var(--accent);
                border-bottom-color: var(--accent);
            }
            .tab-content {
                display: none;
            }
            .tab-content.active {
                display: block;
            }
            header a {
                color: var(--muted);
                text-decoration: none;
            }
            .top-actions {
                display: inline-flex;
                align-items: center;
                gap: 12px;
            }
            #theme-toggle {
                background: var(--panel);
                border: 1px solid rgba(255,255,255,0.1);
                color: var(--ink);
                padding: 8px 12px;
                border-radius: 6px;
                cursor: pointer;
                font-size: 18px;
                transition: all 0.2s;
            }
            #theme-toggle:hover {
                background: var(--accent-dark);
                border-color: var(--accent);
            }
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
                z-index: 30;
                box-shadow: 0 12px 30px rgba(0,0,0,0.35);
                font-size: 14px;
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
            .mode-btn {
                border: 1px solid rgba(255,255,255,0.2);
                background: transparent;
                color: var(--ink);
                padding: 8px 14px;
                border-radius: 999px;
                cursor: pointer;
                font-weight: 600;
            }
            .mode-btn.active {
                background: var(--accent);
                color: #0b2d20;
                border-color: transparent;
            }
            .layout {
                display: grid;
                grid-template-columns: 320px 1fr;
                min-height: calc(100vh - 114px);
            }
            .extracted-text-view {
                background: white;
                height: calc(100vh - 114px);
                overflow: auto;
                padding: 40px;
            }
            .extraction-page {
                position: relative;
                background: white;
                min-height: 1000px;
                padding: 60px 80px;
                margin-bottom: 40px;
                border: 1px solid #e0e0e0;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            }
            .extraction-page-header {
                text-align: center;
                padding: 15px;
                background: #f5f5f5;
                color: #666;
                font-size: 13px;
                font-weight: 600;
                margin: -60px -80px 40px;
            }
            .text-span {
                position: absolute;
                white-space: pre;
                cursor: text;
                padding: 2px;
                border: 1px dashed transparent;
                transition: all 0.15s;
                color: #000000;
            }
            .text-span:hover {
                background: rgba(66, 133, 244, 0.15);
                border-color: rgba(66, 133, 244, 0.4);
            }
            .text-span.editing {
                background: white;
                border: 2px solid #4285f4;
                outline: none;
                z-index: 100;
            }
            .text-span.modified {
                background: rgba(76, 175, 80, 0.2);
                border-color: #4caf50;
            }
            .extracted-loading {
                text-align: center;
                padding: 100px;
                font-size: 18px;
                color: var(--muted);
            }
            .sidebar {
                background: var(--panel);
                padding: 20px;
                border-right: 1px solid rgba(255,255,255,0.08);
                height: calc(100vh - 114px);
                overflow-y: auto;
            }
            .sidebar h2 {
                margin: 0 0 12px;
                font-size: 18px;
                display: flex;
                align-items: center;
                justify-content: space-between;
            }
            .sidebar h2 .gear-icon {
                cursor: pointer;
                font-size: 18px;
                color: var(--muted);
                transition: color 0.2s, transform 0.2s;
            }
            .sidebar h2 .gear-icon:hover {
                color: var(--accent);
                transform: rotate(30deg);
            }
            .sidebar label {
                display: block;
                margin-top: 16px;
                color: var(--muted);
                font-size: 13px;
            }
            .sidebar input, .sidebar textarea, .sidebar select {
                width: 100%;
                margin-top: 6px;
                background: #0c1220;
                border: 1px solid rgba(255,255,255,0.12);
                color: var(--ink);
                padding: 10px;
                border-radius: 10px;
            }
            .sidebar button {
                width: 100%;
                margin-top: 16px;
                border: none;
                padding: 12px;
                border-radius: 999px;
                font-weight: 700;
                cursor: pointer;
            }
            .primary {
                background: var(--accent);
                color: #0b2d20;
            }
            .ghost {
                background: transparent;
                color: var(--muted);
                border: 1px solid rgba(255,255,255,0.2);
            }
            .status {
                margin-top: 12px;
                font-size: 13px;
                color: var(--muted);
            }
            .status.ok { color: var(--accent); }
            .status.err { color: var(--danger); }
            .page-list {
                display: grid;
                gap: 18px;
                margin-top: 12px;
                padding-bottom: 12px;
            }
            .page-thumb {
                display: grid;
                justify-items: center;
                gap: 8px;
                padding: 10px 8px 12px;
                background: rgba(255,255,255,0.04);
                border-radius: 12px;
                border: 1px solid rgba(255,255,255,0.06);
                cursor: pointer;
                transition: transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
            }
            .page-thumb canvas {
                width: 160px;
                height: auto;
                border-radius: 6px;
                background: #fff;
                box-shadow: 0 8px 18px rgba(0,0,0,0.35);
            }
            .page-thumb span {
                font-size: 12px;
                color: var(--muted);
            }
            .page-thumb.active {
                border-color: var(--accent);
                box-shadow: 0 0 0 2px rgba(77, 208, 168, 0.2);
                transform: translateY(-2px);
            }
            .viewer-wrap {
                display: flex;
                flex-direction: column;
                min-height: calc(100vh - 64px);
            }
            .sticky-tools {
                position: sticky;
                top: 0;
                z-index: 100;
                background: linear-gradient(180deg, rgba(7,11,18,0.98), rgba(7,11,18,0.92));
                border-bottom: 1px solid rgba(255,255,255,0.08);
                backdrop-filter: blur(6px);
            }
            .sticky-tools .mode-bar,
            .sticky-tools .toolbar {
                margin: 0;
                border-radius: 0;
            }
            .toolbar {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                align-items: center;
                padding: 14px 20px;
                border-bottom: 1px solid rgba(255,255,255,0.08);
                background: rgba(16, 24, 36, 0.9);
            }
            .toolbar .label {
                color: var(--muted);
                font-size: 13px;
            }
            .toolbar-controls {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                align-items: center;
            }
            .toolbar select,
            .toolbar input[type="number"] {
                background: #0c1220;
                border: 1px solid rgba(255,255,255,0.12);
                color: var(--ink);
                border-radius: 8px;
                padding: 6px 8px;
            }
            .toolbar button {
                border: none;
                padding: 8px 12px;
                border-radius: 0;
                cursor: pointer;
                font-weight: 700;
            }
            .toolbar button.icon-btn {
                width: 32px;
                height: 32px;
                padding: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 18px;
                font-weight: normal;
            }
            .toolbar .pill {
                background: rgba(110,231,183,0.16);
                color: var(--ink);
                border: 1px solid rgba(110,231,183,0.4);
            }
            .toolbar .pill-active {
                background: var(--accent);
                color: #0b2d20;
                border-color: var(--accent);
            }
            .toolbar .danger {
                background: #ff6b6b;
                color: white;
            }
            .toolbar .disabled {
                opacity: 0.5;
                pointer-events: none;
            }
            .selection-toolbar {
                background: #f4f6f8;
                color: #0f172a;
                border-bottom: 1px solid #d7dce3;
            }
            .selection-toolbar .selection-status {
                font-weight: 600;
                color: #475569;
            }
            .selection-toolbar .toolbar-controls {
                gap: 14px;
            }
            .selection-toolbar .toolbar-divider {
                width: 1px;
                height: 28px;
                background: #d7dce3;
            }
            .selection-toolbar .toolbar-group {
                display: inline-flex;
                align-items: center;
                gap: 8px;
            }
            .selection-toolbar select,
            .selection-toolbar input[type="number"] {
                background: #ffffff;
                border: 1px solid #cfd6df;
                color: #0f172a;
                border-radius: 0;
                padding: 6px 8px;
                min-width: 64px;
            }
            .selection-toolbar .tool-icon {
                font-weight: 700;
                color: #334155;
                font-size: 13px;
            }
            .selection-toolbar .tool-btn {
                background: #ffffff;
                color: #0f172a;
                border: 1px solid #cfd6df;
                border-radius: 0;
                padding: 6px 10px;
                font-weight: 700;
                min-width: 32px;
            }
            .selection-toolbar .tool-btn.active {
                background: #0f172a;
                color: #ffffff;
                border-color: #0f172a;
            }
            .selection-toolbar .tool-btn.danger-btn {
                background: #fff1f1;
                color: #b91c1c;
                border-color: #fecaca;
                font-weight: 700;
            }
            .selection-toolbar .color-swatch {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 6px 8px;
                border: 1px solid #cfd6df;
                border-radius: 0;
                background: #ffffff;
                cursor: pointer;
            }
            .selection-toolbar .color-swatch input[type="color"] {
                width: 20px;
                height: 20px;
                padding: 0;
                border: none;
                background: none;
                cursor: pointer;
            }
            .mode-bar {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 10px 20px;
                border-bottom: 1px solid rgba(255,255,255,0.08);
                background: rgba(12, 20, 32, 0.95);
                flex-wrap: wrap;
            }
            .mode-bar .mode-spacer {
                flex: 1 1 auto;
            }
            .mode-bar .divider {
                width: 1px;
                height: 24px;
                background: rgba(255,255,255,0.12);
            }
            .mode-bar button {
                border: none;
                padding: 8px 14px;
                border-radius: 0;
                cursor: pointer;
                background: #1a2636;
                color: var(--ink);
                font-weight: 600;
                display: inline-flex;
                align-items: center;
                gap: 8px;
            }
            .mode-bar button.primary {
                background: var(--accent);
                color: #0b2d20;
            }
            .mode-bar button.ghost {
                background: transparent;
                color: var(--muted);
                border: 1px solid rgba(255,255,255,0.2);
            }
            .mode-bar button.active {
                background: var(--accent);
                color: #0b2d20;
            }
            .mode-bar .icon {
                font-weight: 800;
                font-size: 16px;
            }
            .toggle-switch {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                padding: 6px 10px;
                border: 1px solid rgba(255,255,255,0.2);
                background: #1a2636;
                color: var(--ink);
                font-weight: 600;
                border-radius: 0;
                cursor: pointer;
                user-select: none;
            }
            .toggle-switch input {
                display: none;
            }
            .toggle-switch .slider {
                position: relative;
                width: 36px;
                height: 18px;
                background: rgba(255,255,255,0.2);
                border: 1px solid rgba(255,255,255,0.3);
                border-radius: 0;
                transition: all 0.2s;
                flex-shrink: 0;
            }
            .toggle-switch .slider::after {
                content: '';
                position: absolute;
                top: 1px;
                left: 1px;
                width: 14px;
                height: 14px;
                background: #ffffff;
                transition: all 0.2s;
                border-radius: 0;
            }
            .toggle-switch input:checked + .slider {
                background: var(--accent);
                border-color: var(--accent);
            }
            .toggle-switch input:checked + .slider::after {
                left: 19px;
                background: #0b2d20;
            }
            .viewer {
                padding: 24px;
                overflow: auto;
                flex: 1;
            }
            .viewer-footer {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 16px;
                padding: 16px 24px 28px;
                color: var(--muted);
            }
            .viewer-footer button {
                border: 1px solid rgba(255,255,255,0.2);
                background: transparent;
                color: var(--ink);
                padding: 8px 16px;
                border-radius: 0;
                cursor: pointer;
                font-weight: 600;
            }
            .viewer-footer button[disabled] {
                opacity: 0.6;
                cursor: not-allowed;
            }
            .pdf-text-layer {
                position: absolute;
                inset: 0;
                pointer-events: none;
                z-index: 50;
                display: none;
            }
            .pdf-text {
                position: absolute;
                pointer-events: none;
                white-space: pre;
                color: transparent;
                background: transparent;
                cursor: pointer;
            }
            /* Edit text mode - text areas become clickable */
            .viewer.edit-text-mode .pdf-text-layer {
                display: block;
                pointer-events: auto;
            }
            .viewer.edit-text-mode .pdf-text {
                pointer-events: auto;
                cursor: pointer;
            }
            .viewer.edit-text-mode .pdf-text:hover {
                background: rgba(66, 133, 244, 0.2);
                outline: 2px solid rgba(66, 133, 244, 0.6);
            }
            /* Active text editor popup */
            .text-edit-popup {
                position: absolute;
                z-index: 200;
                background: #fff;
                border: 2px solid #4285f4;
                border-radius: 4px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                padding: 0;
                min-width: 150px;
            }
            .text-edit-popup input {
                border: none;
                outline: none;
                padding: 8px 12px;
                font-size: inherit;
                font-family: inherit;
                width: 100%;
                box-sizing: border-box;
                background: #fff;
                color: #000;
            }
            .text-edit-popup .popup-actions {
                display: flex;
                border-top: 1px solid #e0e0e0;
                background: #f5f5f5;
            }
            .text-edit-popup .popup-actions button {
                flex: 1;
                border: none;
                padding: 6px 12px;
                cursor: pointer;
                font-size: 12px;
                font-weight: 600;
            }
            .text-edit-popup .popup-actions .save-btn {
                background: #4285f4;
                color: white;
            }
            .text-edit-popup .popup-actions .save-btn:hover {
                background: #3367d6;
            }
            .text-edit-popup .popup-actions .cancel-btn {
                background: #f5f5f5;
                color: #666;
            }
            .text-edit-popup .popup-actions .cancel-btn:hover {
                background: #e0e0e0;
            }
            /* Modified text indicator */
            .pdf-text.modified {
                background: rgba(76, 175, 80, 0.15) !important;
                outline: 2px solid rgba(76, 175, 80, 0.5) !important;
            }
            /* Edit text mode info banner */
            .edit-text-banner {
                display: none;
                background: #e3f2fd;
                border: 1px solid #90caf9;
                border-radius: 4px;
                padding: 10px 16px;
                margin: 0 20px 12px;
                color: #1565c0;
                font-size: 13px;
            }
            .edit-text-banner.visible {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .edit-text-banner .icon {
                font-size: 18px;
            }
            .edit-text-banner .count {
                margin-left: auto;
                background: #4caf50;
                color: white;
                padding: 2px 10px;
                border-radius: 12px;
                font-weight: 600;
                font-size: 12px;
            }
            .viewer.text-editing .pdf-text,
            .viewer.text-editing .pdf-text-item {
                background: white;
                color: #000;
            }
            /* Non-edit mode: Hide text by default */
            .viewer:not(.text-editing) .pdf-text,
            .viewer:not(.text-editing) .pdf-text-item {
                opacity: 0;
                pointer-events: none;
            }
            .viewer:not(.text-editing) .pdf-text-layer {
                display: none;
            }
            .viewer.text-editing .pdf-text-layer {
                display: block !important;
            }
            .viewer.text-editing .pdf-text-item {
                opacity: 1 !important;
                pointer-events: auto !important;
            }
            .viewer.text-editing .pdf-text {
                color: rgba(0,0,0,0.55);
            }
            .viewer.text-editing .pdf-text:hover {
                background-color: rgba(110, 231, 183, 0.15);
                outline: 1px dashed rgba(110, 231, 183, 0.4);
            }
            .pdf-text.editing {
                outline: 2px solid var(--accent);
                background-color: rgba(110, 231, 183, 0.2);
            }
            .overlay-field .resize-handle {
                position: absolute;
                background: var(--accent);
                z-index: 10;
            }
            .overlay-field .resize-handle.corner {
                width: 8px;
                height: 8px;
                border-radius: 50%;
            }
            .overlay-field .resize-handle.edge {
                background: rgba(110, 231, 183, 0.6);
            }
            .overlay-field .move-handle {
                position: absolute;
                top: -32px;
                right: 0;
                width: 24px;
                height: 24px;
                background: rgba(77, 208, 168, 0.9);
                border: 2px solid white;
                border-radius: 50%;
                cursor: move;
                z-index: 15;
                display: none;
                align-items: center;
                justify-content: center;
                font-size: 14px;
                color: white;
                box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            }
            .overlay-field.active .move-handle {
                display: flex;
            }
            .overlay-field .move-handle:hover {
                background: var(--accent);
                transform: scale(1.1);
            }
            .overlay-field .delete-handle {
                position: absolute;
                top: -32px;
                right: 32px;
                width: 24px;
                height: 24px;
                background: rgba(255, 107, 107, 0.9);
                border: 2px solid white;
                border-radius: 50%;
                cursor: pointer;
                z-index: 15;
                display: none;
                align-items: center;
                justify-content: center;
                font-size: 14px;
                color: white;
                box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            }
            .overlay-field.active .delete-handle {
                display: flex;
            }
            .overlay-field .delete-handle:hover {
                background: var(--danger);
                transform: scale(1.1);
            }
            .overlay-field .resize-handle.n { top: -4px; left: 50%; width: 14px; height: 6px; transform: translateX(-50%); cursor: ns-resize; }
            .overlay-field .resize-handle.s { bottom: -4px; left: 50%; width: 14px; height: 6px; transform: translateX(-50%); cursor: ns-resize; }
            .overlay-field .resize-handle.e { right: -4px; top: 50%; width: 6px; height: 14px; transform: translateY(-50%); cursor: ew-resize; }
            .overlay-field .resize-handle.w { left: -4px; top: 50%; width: 6px; height: 14px; transform: translateY(-50%); cursor: ew-resize; }
            .overlay-field .resize-handle.ne { right: -4px; top: -4px; cursor: nesw-resize; }
            .overlay-field .resize-handle.nw { left: -4px; top: -4px; cursor: nwse-resize; }
            .overlay-field .resize-handle.se { right: -4px; bottom: -4px; cursor: nwse-resize; }
            .overlay-field .resize-handle.sw { left: -4px; bottom: -4px; cursor: nesw-resize; }
            .page {
                position: relative;
                margin: 0 auto 24px;
                width: fit-content;
                box-shadow: 0 12px 30px rgba(0,0,0,0.35);
                border-radius: 8px;
                overflow: hidden;
                background: #0f1522;
            }
            .overlay-page {
                position: relative;
                margin: 0 auto 24px;
                box-shadow: 0 12px 30px rgba(0,0,0,0.35);
                border-radius: 8px;
                overflow: hidden;
                background: #0f1522;
            }
            .page canvas {
                display: block;
            }
            .overlay {
                position: absolute;
                inset: 0;
            }
            .viewer.overlay-view-mode .overlay-field {
                border: none !important;
                background: transparent !important;
                cursor: default !important;
                box-shadow: none !important;
            }
            .viewer.overlay-view-mode .overlay-field [contenteditable] {
                cursor: default !important;
            }
            .viewer.overlay-view-mode .resize-handle,
            .viewer.overlay-view-mode .move-handle,
            .viewer.overlay-view-mode .delete-handle {
                display: none !important;
            }
            .viewer.overlay-hidden .overlay-field {
                display: none !important;
            }
            .viewer.overlay-hidden .overlay-field .resize-handle,
            .overlay-field.selected {
                border-color: var(--accent) !important;
                box-shadow: 0 0 0 2px rgba(77, 208, 168, 0.25) !important;
            }
            .annotation {
                position: absolute;
                color: #111;
                background: transparent;
                padding: 2px 6px;
                border-radius: 6px;
                cursor: move;
                user-select: none;
                display: inline-flex;
                align-items: center;
                gap: 4px;
                border: 1px solid transparent;
            }
            .annotation.dragging {
                cursor: grabbing;
                opacity: 0.9;
            }
            .annotation.selected {
                background: rgba(255,255,255,0.82);
                border-color: var(--accent-dark);
                box-shadow: 0 0 0 2px rgba(110,231,183,0.35);
            }
            .annotation:hover {
                background: rgba(255,255,255,0.95);
            }
            .annotation .delete-btn {
                background: #ff6b6b;
                color: white;
                border: none;
                border-radius: 50%;
                width: 18px;
                height: 18px;
                cursor: pointer;
                font-size: 12px;
                line-height: 1;
                display: none;
                align-items: center;
                justify-content: center;
                margin-left: 4px;
            }
            .annotation.selected .delete-btn {
                display: flex;
            }
            .annotation .delete-btn:hover {
                background: #ff4444;
            }
            .annotation .annotation-text {
                display: inline-block;
                padding: 1px 2px;
                border-radius: 4px;
            }
            .text-editor {
                position: absolute;
                background: white;
                color: #111;
                border: 2px solid var(--accent);
                border-radius: 6px;
                padding: 6px 10px;
                min-width: 120px;
                min-height: 32px;
                outline: none;
                font-family: inherit;
                z-index: 100;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
                resize: both;
            }
            /* Field group styling */
            .field-group {
                position: absolute;
                border: 2px dashed rgba(110, 231, 183, 0.4);
                background: rgba(110, 231, 183, 0.03);
                border-radius: 12px;
                pointer-events: none;
                z-index: 1;
                box-shadow: inset 0 0 20px rgba(110, 231, 183, 0.08);
            }
            .modal {
                position: fixed;
                inset: 0;
                background: rgba(5, 8, 14, 0.75);
                display: none;
                align-items: center;
                justify-content: center;
                z-index: 200;
            }
            .modal.active {
                display: flex;
            }
            .organize-modal .modal-card {
                max-width: 900px;
                width: 90vw;
            }

            .organize-toolbar {
                display: flex;
                gap: 8px;
                padding: 12px 20px;
                background: var(--bg-secondary);
                border-bottom: 1px solid var(--border-color);
                flex-wrap: wrap;
            }

            .organize-toolbar button {
                display: flex;
                align-items: center;
                gap: 6px;
                padding: 8px 12px;
                background: var(--bg-primary);
                border: 1px solid var(--border-color);
                border-radius: 0;
                color: var(--text-primary);
                cursor: pointer;
                font-size: 14px;
                transition: all 0.2s;
            }

            .organize-toolbar button:hover:not(:disabled) {
                background: var(--hover-bg);
                border-color: var(--accent);
            }

            .organize-toolbar button:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }

            .organize-toolbar button.danger:hover:not(:disabled) {
                background: #fee;
                border-color: #f44;
                color: #c00;
            }

            .organize-pages-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
                gap: 16px;
                padding: 20px;
                max-height: 60vh;
                overflow-y: auto;
                background: #f9fafb;
            }
            .organize-page-item {
                position: relative;
                cursor: move;
                background: white;
                border: 2px solid #e5e7eb;
                border-radius: 8px;
                padding: 8px;
                transition: all 0.2s;
            }
            .organize-page-item:hover {
                border-color: var(--accent);
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            }
            .organize-page-item.dragging {
                opacity: 0.5;
                transform: scale(0.95);
            }
            .organize-page-item.drag-over {
                border-color: var(--accent);
                background: rgba(16, 185, 129, 0.1);
            }
            .organize-page-item.selected {
                border-color: #3b82f6;
                background: rgba(59, 130, 246, 0.1);
                box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.3);
            }
            .organize-page-item canvas {
                width: 100%;
                height: auto;
                border-radius: 4px;
                background: #fff;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            .organize-page-item .page-number {
                position: absolute;
                top: 4px;
                left: 4px;
                background: rgba(0,0,0,0.7);
                color: white;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 12px;
                font-weight: 600;
            }
            .modal-card {
                background: #ffffff;
                border: 1px solid #e5e7eb;
                border-radius: 12px;
                padding: 0;
                width: min(640px, 94vw);
                box-shadow: 0 20px 50px rgba(0,0,0,0.35);
                color: #111827;
            }
            .modal-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 16px 20px;
                border-bottom: 1px solid #e5e7eb;
                font-weight: 700;
            }
            .modal-close {
                background: transparent;
                border: none;
                font-size: 20px;
                cursor: pointer;
                color: #6b7280;
            }
            .signature-tabs {
                display: flex;
                gap: 12px;
                padding: 14px 20px 10px;
            }
            .signature-tab {
                flex: 1;
                border: 1px solid #e5e7eb;
                background: #f9fafb;
                color: #6b7280;
                border-radius: 10px;
                padding: 10px 12px;
                font-weight: 600;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                cursor: pointer;
            }
            .signature-tab.active {
                background: #fff1f2;
                border-color: #fecdd3;
                color: #ef4444;
            }
            .signature-panel {
                display: none;
            }
            .signature-panel.active {
                display: block;
            }
            .signature-controls {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px 20px;
                border-top: 1px solid #f3f4f6;
                border-bottom: 1px solid #f3f4f6;
                background: #f9fafb;
                flex-wrap: wrap;
            }
            .signature-controls .color-pill {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 6px 10px;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                background: #ffffff;
                font-size: 12px;
                color: #6b7280;
            }
            .signature-controls input[type="color"] {
                width: 28px;
                height: 28px;
                padding: 0;
                border: none;
                background: transparent;
            }
            .signature-controls .slider {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 6px 10px;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                background: #ffffff;
                font-size: 12px;
                color: #6b7280;
            }
            .signature-controls input[type="range"] {
                width: 140px;
            }
            .signature-canvas-wrap {
                padding: 14px 20px 6px;
            }
            .signature-canvas {
                width: 100%;
                height: 220px;
                background: #f7f7f7;
                border-radius: 8px;
                border: 1px solid #e5e7eb;
                cursor: crosshair;
            }
            .signature-write-controls {
                display: grid;
                grid-template-columns: 200px 1fr;
                gap: 12px;
                padding: 12px 20px;
                border-top: 1px solid #f3f4f6;
                border-bottom: 1px solid #f3f4f6;
                background: #f9fafb;
            }
            .signature-write-controls select,
            .signature-write-controls input[type="text"] {
                padding: 10px 12px;
                border-radius: 8px;
                border: 1px solid #e5e7eb;
                font-size: 14px;
            }
            .modal-actions {
                display: flex;
                justify-content: space-between;
                gap: 12px;
                padding: 14px 20px 18px;
                border-top: 1px solid #f3f4f6;
                background: #ffffff;
            }
            .modal-actions button {
                flex: 1;
                border-radius: 8px;
                padding: 10px 12px;
                font-weight: 600;
            }
            .modal-actions .primary {
                background: #d1d5db;
                color: #9ca3af;
            }
            .modal-actions .ghost {
                background: #ffffff;
                border: 1px solid #e5e7eb;
                color: #111827;
            }
            .save-spinner {
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.65);
                display: none;
                align-items: center;
                justify-content: center;
                z-index: 240;
            }
            .save-spinner.active {
                display: flex;
            }
            .save-spinner-card {
                background: #ffffff;
                border-radius: 14px;
                padding: 24px 28px;
                box-shadow: 0 22px 50px rgba(0,0,0,0.35);
                display: grid;
                gap: 10px;
                justify-items: center;
                color: #111827;
                min-width: 220px;
            }
            .save-spinner-ring {
                width: 42px;
                height: 42px;
                border-radius: 999px;
                border: 4px solid #e5e7eb;
                border-top-color: var(--accent);
                animation: spin 0.9s linear infinite;
            }
            .save-spinner-text {
                font-size: 14px;
                font-weight: 600;
                color: #334155;
            }
            @keyframes spin {
                to { transform: rotate(360deg); }
            }
            .hint {
                margin-top: 12px;
                color: var(--muted);
                font-size: 13px;
            }
            .ocr-document-view {
                width: 100%;
                height: 100%;
                overflow-y: auto;
                background: #f5f5f5;
                padding: 40px;
            }
            .ocr-loading {
                text-align: center;
                padding: 60px;
                color: #666;
                font-size: 18px;
            }
            .ocr-document {
                max-width: 850px;
                margin: 0 auto;
                background: white;
                padding: 60px 80px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                font-family: 'Times New Roman', serif;
                font-size: 14px;
                line-height: 1.8;
                min-height: 800px;
            }
            .ocr-page {
                margin-bottom: 60px;
                border-bottom: 1px solid #ddd;
                padding-bottom: 40px;
            }
            .ocr-page:last-child {
                border-bottom: none;
            }
            .ocr-page-number {
                text-align: center;
                color: #999;
                font-size: 12px;
                margin-bottom: 30px;
                font-family: Arial, sans-serif;
            }
            .ocr-paragraph {
                margin-bottom: 20px;
                text-align: justify;
                color: #000;
            }
            .ocr-paragraph[contenteditable]:hover {
                background: #fffbea;
                cursor: text;
                padding: 8px;
                margin: -8px;
                color: #000;
            }
            .ocr-paragraph[contenteditable]:focus {
                outline: 2px solid #4CAF50;
                background: white;
                padding: 8px;
                margin: -8px;
                color: #000;
            }
            @media (max-width: 980px) {
                .layout {
                    grid-template-columns: 1fr;
                }
                .sidebar {
                    border-right: none;
                    border-bottom: 1px solid rgba(255,255,255,0.08);
                }
                .annotations-panel {
                    display: none !important;
                }
            }
            
            /* Annotations Panel */
            .annotations-panel {
                position: fixed;
                right: 0;
                top: 114px;
                width: 280px;
                height: calc(100vh - 114px);
                background: var(--panel);
                border-left: 1px solid rgba(255,255,255,0.08);
                display: flex;
                flex-direction: column;
                transition: transform 0.3s ease;
                z-index: 1000;
            }
            .annotations-panel.collapsed {
                transform: translateX(280px);
            }
            .annotations-panel-toggle {
                position: absolute;
                left: -40px;
                top: 50%;
                transform: translateY(-50%);
                width: 40px;
                height: 80px;
                background: var(--panel);
                border: 1px solid rgba(255,255,255,0.08);
                border-right: none;
                border-radius: 8px 0 0 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                font-size: 20px;
                color: var(--muted);
                transition: color 0.2s;
            }
            .annotations-panel-toggle:hover {
                color: var(--accent);
            }
            .annotations-panel-header {
                padding: 20px;
                border-bottom: 1px solid rgba(255,255,255,0.08);
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .annotations-panel-header h2 {
                margin: 0;
                font-size: 18px;
                font-weight: 600;
            }
            .annotations-panel-header .remove-all-btn {
                background: transparent;
                border: none;
                color: #ff6b6b;
                cursor: pointer;
                font-size: 14px;
                padding: 4px 8px;
                border-radius: 4px;
                transition: background 0.2s;
            }
            .annotations-panel-header .remove-all-btn:hover {
                background: rgba(255,107,107,0.1);
            }
            .annotations-panel-content {
                flex: 1;
                overflow-y: auto;
                padding: 12px;
            }
            .annotations-panel-content:empty::before {
                content: 'No annotations yet';
                display: block;
                text-align: center;
                color: var(--muted);
                padding: 40px 20px;
                font-size: 14px;
            }
            .annotation-item {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px;
                background: rgba(255,255,255,0.04);
                border-radius: 8px;
                margin-bottom: 8px;
                cursor: grab;
                transition: background 0.2s;
            }
            .annotation-item:hover {
                background: rgba(255,255,255,0.08);
            }
            .annotation-item.dragging {
                opacity: 0.5;
                cursor: grabbing;
            }
            .annotation-item-icon {
                width: 32px;
                height: 32px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: rgba(77, 208, 168, 0.15);
                border-radius: 6px;
                font-size: 16px;
                flex-shrink: 0;
            }
            .annotation-item-content {
                flex: 1;
                min-width: 0;
            }
            .annotation-item-title {
                font-size: 14px;
                font-weight: 500;
                color: var(--ink);
                margin-bottom: 2px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .annotation-item-meta {
                font-size: 12px;
                color: var(--muted);
            }
            .annotation-item-actions {
                display: flex;
                gap: 4px;
                flex-shrink: 0;
            }
            .annotation-item-action {
                width: 28px;
                height: 28px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: transparent;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                color: var(--muted);
                transition: all 0.2s;
                font-size: 14px;
            }
            .annotation-item-action:hover {
                background: rgba(255,255,255,0.08);
                color: var(--accent);
            }
            .annotation-item-action.delete:hover {
                background: rgba(255,107,107,0.1);
                color: #ff6b6b;
            }
        </style>
    </head>
    <body>
        <header>
            <div class="header-title">
                <div>
                    <strong>Edit:</strong> {{ $document->original_name }}
                </div>
                <div class="header-history">
                    <button id="overlay-undo" type="button" class="header-icon-btn" title="Undo (Ctrl+Z)" disabled>←</button>
                    <button id="overlay-redo" type="button" class="header-icon-btn" title="Redo (Ctrl+Y)" disabled>→</button>
                </div>
            </div>
            <div class="top-actions">
                <button id="theme-toggle" type="button" title="Toggle theme">🌙</button>
                <a href="{{ route('documents.index') }}">Back to uploads</a>
            </div>
        </header>
        <div class="tab-nav">
            <button class="tab-btn active" data-tab="pdf-editor">📄 PDF Editor</button>
            <button class="tab-btn" data-tab="extracted-text" id="extracted-text-tab">✎ Edit Extracted Text</button>
        </div>
        <div class="tab-content active" id="pdf-editor">
        <div class="layout">
            <aside class="sidebar">
                <h2>
                    <span>Pages</span>
                    <span class="gear-icon" id="organize-pages-btn" title="Organize Pages">⚙️</span>
                </h2>
                <div class="page-list" id="page-list"></div>
                <div class="status" id="status"></div>
            </aside>
            <main class="viewer-wrap">
                <div class="sticky-tools">
                    <div class="mode-bar">
                        <label class="toggle-switch" id="mode-overlay-toggle">
                            <input type="checkbox" id="mode-overlay">
                            <span class="slider"></span>
                            <span class="toggle-label">Overlay Editor</span>
                        </label>
                        <button type="button" id="mode-text">
                            <span class="icon">T</span>
                            Add Text
                        </button>
                        <button type="button" id="mode-edit-text">
                            <span class="icon">✎</span>
                            Edit Text
                        </button>
                        <button type="button" id="mode-sign">
                            <span class="icon">✒</span>
                            Sign
                        </button>
                        <button type="button" id="mode-shape">
                            <span class="icon">▭</span>
                            Shapes
                        </button>
                        <button id="insert-x" class="pill" type="button" title="Add X">✖</button>
                        <button id="insert-checkbox" class="pill" type="button" title="Add Checkbox">☑</button>
                        <button type="button" id="view-original-pdf" class="pill">👁 View Original PDF</button>
                        <span class="divider"></span>
                        <span class="mode-spacer"></span>
                        <button id="save-btn" class="primary" type="button">Save PDF</button>
                        <button id="save-overlay-btn" class="primary" type="button" style="display:none;">Save Changes</button>
                        <button id="clear-btn" class="ghost" type="button">Clear All Changes</button>
                    </div>
                </div>
                <div class="toolbar selection-toolbar" id="selection-bar">
                    <span class="selection-status" id="selection-label">No text selected</span>
                    <div class="toolbar-controls" id="selection-controls">
                        <div class="toolbar-group">
                            <span class="tool-icon">T</span>
                            <select id="selected-font">
                                <option value="Helvetica">Helvetica</option>
                                <option value="TimesRoman">Times</option>
                                <option value="Courier">Courier</option>
                            </select>
                            <select id="selected-weight" title="Font Weight">
                                <option value="100">Thin</option>
                                <option value="200">Extra Light</option>
                                <option value="300">Light</option>
                                <option value="400" selected>Normal</option>
                                <option value="500">Medium</option>
                                <option value="600">Semi Bold</option>
                                <option value="700">Bold</option>
                                <option value="800">Extra Bold</option>
                                <option value="900">Black</option>
                            </select>
                            <span class="tool-icon">T</span>
                            <input id="selected-size" type="number" min="8" max="48" value="16" style="width: 64px;">
                        </div>
                        <div class="toolbar-divider"></div>
                        <div class="toolbar-group">
                            <button id="selected-bold" class="tool-btn" type="button" title="Bold">B</button>
                            <button id="selected-italic" class="tool-btn" type="button" title="Italic">I</button>
                            <button id="selected-underline" class="tool-btn" type="button" title="Underline">U</button>
                        </div>
                        <div class="toolbar-group">
                            <label id="selected-color-swatch" class="color-swatch" title="Font color">
                                <span class="tool-icon">A</span>
                                <input id="selected-color" type="color" value="#111111">
                            </label>
                            <label id="selected-bg-swatch" class="color-swatch" title="Background fill">
                                <span class="tool-icon">▨</span>
                                <input id="selected-bg" type="color" value="#ffffff">
                            </label>
                        </div>
                        <div class="toolbar-group">
                            <select id="selected-align" title="Alignment">
                                <option value="left">Left</option>
                                <option value="center">Center</option>
                                <option value="right">Right</option>
                            </select>
                        </div>
                        <div class="toolbar-group">
                            <select id="selected-opacity" title="Transparency">
                                <option value="1">100%</option>
                                <option value="0.75">75%</option>
                                <option value="0.5">50%</option>
                                <option value="0.25">25%</option>
                            </select>
                        </div>
                        <button id="selected-delete" class="tool-btn danger-btn" type="button" title="Delete">🗑</button>
                    </div>
                </div>
                <div class="edit-text-banner" id="edit-text-banner">
                    <span class="icon">✎</span>
                    <span><strong>Edit Text Mode:</strong> Click on any text to edit it. Press Enter to save, Escape to cancel.</span>
                    <span class="count" id="modified-count">0 modified</span>
                </div>
                <div class="viewer" id="viewer"></div>
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
        <div class="tab-content" id="extracted-text">
            <div class="extracted-text-view" id="extracted-text-view">
                <div class="extracted-loading">Loading extracted text...</div>
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
            <span class="zoom-label" id="zoom-label">120%</span>
            <span class="divider"></span>
            <button type="button" id="page-next" aria-label="Next page">›</button>
        </div>

        <!-- Annotations Panel -->
        <div class="annotations-panel" id="annotations-panel">
            <div class="annotations-panel-toggle" id="annotations-toggle">
                📋
            </div>
            <div class="annotations-panel-header">
                <h2>Edit PDF</h2>
                <button class="remove-all-btn" id="remove-all-annotations">Remove all</button>
            </div>
            <div class="annotations-panel-content" id="annotations-list">
                <!-- Annotations will be dynamically populated here -->
            </div>
        </div>

        <div class="modal organize-modal" id="organize-pages-modal" aria-hidden="true">
            <div class="modal-card">
                <div class="modal-header">
                    <span>Organize Pages</span>
                    <button class="modal-close" type="button" id="organize-close">×</button>
                </div>
                <div class="organize-toolbar">
                    <button id="delete-page-btn" type="button" class="danger" title="Delete selected page">
                        <span>🗑️</span> Delete
                    </button>
                    <button id="add-page-btn" type="button" title="Add a new blank page">
                        <span>➕</span> Add page
                    </button>
                    <button id="rotate-page-btn" type="button" title="Rotate selected page">
                        <span>🔄</span> Rotate
                    </button>
                    <button id="move-left-btn" type="button" title="Move selected page left">
                        <span>⬅️</span> Move left
                    </button>
                    <button id="move-right-btn" type="button" title="Move selected page right">
                        <span>➡️</span> Move right
                    </button>
                    <button id="move-to-btn" type="button" title="Move selected page to specific position">
                        <span>📍</span> Move to
                    </button>
                    <button id="duplicate-page-btn" type="button" title="Duplicate selected page">
                        <span>📄</span> Duplicate
                    </button>
                </div>
                <div class="organize-pages-grid" id="organize-pages-grid">
                    <!-- Pages will be dynamically populated here -->
                </div>
                <div class="modal-actions">
                    <button id="organize-cancel" class="ghost" type="button">Cancel</button>
                    <button id="organize-apply" class="primary" type="button" style="background: #10b981; color: white;">Apply</button>
                </div>
            </div>
        </div>

        <div class="modal" id="signature-modal" aria-hidden="true">
            <div class="modal-card">
                <div class="modal-header">
                    <span>Create signature</span>
                    <button class="modal-close" type="button" id="signature-close">×</button>
                </div>
                <div class="signature-tabs">
                    <button class="signature-tab active" type="button" data-signature-tab="draw">✍ Draw signature</button>
                    <button class="signature-tab" type="button" data-signature-tab="write">⌨ Write signature</button>
                    <button class="signature-tab" type="button" data-signature-tab="image">🖼 Use image</button>
                </div>
                <div class="signature-panel active" data-signature-panel="draw">
                    <div class="signature-controls">
                        <div class="color-pill">
                            <input id="signature-color" type="color" value="#000000" aria-label="Signature color">
                            <span>#000000</span>
                        </div>
                        <div class="slider">
                            <span>Stroke</span>
                            <input id="signature-width" type="range" min="1" max="8" value="3">
                            <span id="signature-width-label">3</span>
                        </div>
                        <button id="signature-clear" class="ghost" type="button" style="margin-left:auto;">Clear</button>
                    </div>
                </div>
                <div class="signature-panel" data-signature-panel="write">
                    <div class="signature-write-controls">
                        <select id="signature-font">
                            <option value="Great Vibes" selected>Great Vibes</option>
                            <option value="Dancing Script">Dancing Script</option>
                            <option value="Allura">Allura</option>
                            <option value="Pacifico">Pacifico</option>
                        </select>
                        <input id="signature-text" type="text" placeholder="Type your signature">
                    </div>
                </div>
                <div class="signature-panel" data-signature-panel="image">
                    <div class="signature-write-controls">
                        <div style="color:#6b7280;font-size:13px;">Image upload not wired yet.</div>
                    </div>
                </div>
                <div class="signature-canvas-wrap">
                    <canvas id="signature-canvas" class="signature-canvas" width="900" height="300"></canvas>
                </div>
                <div class="modal-actions">
                    <button id="signature-cancel" class="ghost" type="button">Cancel</button>
                    <button id="signature-save" class="primary" type="button" disabled>Create and use</button>
                </div>
            </div>
        </div>
        
        <!-- Shape Settings Modal -->
        <div class="modal" id="shape-modal" aria-hidden="true">
            <div class="modal-card">
                <div class="modal-header">
                    <span>Shape Settings</span>
                    <button class="modal-close" type="button" id="shape-close">×</button>
                </div>
                <div style="padding: 24px;">
                    <!-- Shape Type -->
                    <div style="margin-bottom: 24px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <label style="font-weight: 600; font-size: 14px;">Shape type</label>
                        </div>
                        <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                            <button type="button" class="shape-type-btn active" data-shape="circle" style="width: 48px; height: 48px; border: 2px solid var(--accent); background: rgba(110, 231, 183, 0.1); border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s;">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                </svg>
                            </button>
                            <button type="button" class="shape-type-btn" data-shape="triangle" style="width: 48px; height: 48px; border: 2px solid rgba(255,255,255,0.2); background: transparent; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s;">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 3 L21 21 L3 21 Z"/>
                                </svg>
                            </button>
                            <button type="button" class="shape-type-btn" data-shape="rect" style="width: 48px; height: 48px; border: 2px solid rgba(255,255,255,0.2); background: transparent; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s;">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="3" width="18" height="18"/>
                                </svg>
                            </button>
                            <button type="button" class="shape-type-btn" data-shape="x" style="width: 48px; height: 48px; border: 2px solid rgba(255,255,255,0.2); background: transparent; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s;">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                    <line x1="18" y1="6" x2="6" y2="18"/>
                                    <line x1="6" y1="6" x2="18" y2="18"/>
                                </svg>
                            </button>
                            <button type="button" class="shape-type-btn" data-shape="checkmark" style="width: 48px; height: 48px; border: 2px solid rgba(255,255,255,0.2); background: transparent; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s;">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                            </button>
                            <button type="button" class="shape-type-btn" data-shape="star" style="width: 48px; height: 48px; border: 2px solid rgba(255,255,255,0.2); background: transparent; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s;">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round">
                                    <polygon points="12,2 15.09,8.26 22,9.27 17,14.14 18.18,21.02 12,17.77 5.82,21.02 7,14.14 2,9.27 8.91,8.26"/>
                                </svg>
                            </button>
                            <button type="button" class="shape-type-btn" data-shape="polygon" style="width: 48px; height: 48px; border: 2px solid rgba(255,255,255,0.2); background: transparent; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s;">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round">
                                    <polygon points="12,2 22,8.5 22,15.5 12,22 2,15.5 2,8.5"/>
                                </svg>
                            </button>
                            <button type="button" class="shape-type-btn" data-shape="arrow" style="width: 48px; height: 48px; border: 2px solid rgba(255,255,255,0.2); background: transparent; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s;">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="5" y1="12" x2="19" y2="12"/>
                                    <polyline points="12 5 19 12 12 19"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Stroke -->
                    <div style="margin-bottom: 24px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <label style="font-weight: 600; font-size: 14px;">Stroke</label>
                        </div>
                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                            <div style="position: relative; width: 40px; height: 40px; border-radius: 6px; overflow: hidden; border: 1px solid rgba(255,255,255,0.2);">
                                <input type="color" id="shape-stroke-color" value="#000000" style="position: absolute; width: 200%; height: 200%; top: -50%; left: -50%; border: none; cursor: pointer;">
                            </div>
                            <input type="text" id="shape-stroke-hex" value="#000000" style="flex: 1; background: var(--panel); border: 1px solid rgba(255,255,255,0.2); border-radius: 6px; padding: 8px 12px; color: var(--ink); font-size: 14px;">
                        </div>
                        <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px; cursor: pointer;">
                            <input type="checkbox" id="shape-stroke-transparent" style="width: 18px; height: 18px; cursor: pointer;">
                            <span style="font-size: 14px;">Transparent</span>
                        </label>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="flex: 1; height: 4px; background: rgba(255,255,255,0.1); border-radius: 2px; position: relative;">
                                <input type="range" id="shape-stroke-width" min="1" max="20" value="2" style="position: absolute; width: 100%; height: 100%; opacity: 0; cursor: pointer;">
                                <div id="shape-stroke-width-fill" style="height: 100%; background: var(--accent); border-radius: 2px; width: 10%; pointer-events: none;"></div>
                            </div>
                            <span id="shape-stroke-width-label" style="font-size: 14px; min-width: 40px;">10%</span>
                        </div>
                    </div>
                    
                    <!-- Fill Color -->
                    <div style="margin-bottom: 24px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <label style="font-weight: 600; font-size: 14px;">Fill Color</label>
                        </div>
                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                            <div style="position: relative; width: 40px; height: 40px; border-radius: 6px; overflow: hidden; border: 1px solid rgba(255,255,255,0.2);">
                                <input type="color" id="shape-fill-color" value="#000000" style="position: absolute; width: 200%; height: 200%; top: -50%; left: -50%; border: none; cursor: pointer;">
                            </div>
                            <input type="text" id="shape-fill-hex" value="#000000" style="flex: 1; background: var(--panel); border: 1px solid rgba(255,255,255,0.2); border-radius: 6px; padding: 8px 12px; color: var(--ink); font-size: 14px;">
                        </div>
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" id="shape-fill-transparent" checked style="width: 18px; height: 18px; cursor: pointer;">
                            <span style="font-size: 14px;">Transparent</span>
                        </label>
                    </div>
                    
                    <!-- Opacity -->
                    <div style="margin-bottom: 24px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <label style="font-weight: 600; font-size: 14px;">Opacity</label>
                        </div>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="flex: 1; height: 4px; background: rgba(255,255,255,0.1); border-radius: 2px; position: relative;">
                                <input type="range" id="shape-opacity" min="0" max="100" value="100" style="position: absolute; width: 100%; height: 100%; opacity: 0; cursor: pointer;">
                                <div id="shape-opacity-fill" style="height: 100%; background: #ef4444; border-radius: 2px; width: 100%; pointer-events: none;"></div>
                            </div>
                            <span id="shape-opacity-label" style="font-size: 14px; min-width: 50px;">100%</span>
                        </div>
                    </div>
                    
                    <button type="button" id="shape-apply" class="primary" style="width: 100%; padding: 12px; background: var(--accent); color: #053322; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 14px;">
                        Apply & Draw Shape
                    </button>
                </div>
            </div>
        </div>
        
        <div class="save-spinner" id="save-spinner" aria-hidden="true">
            <div class="save-spinner-card">
                <div class="save-spinner-ring" aria-hidden="true"></div>
                <div class="save-spinner-text" id="save-spinner-text">Saving and refreshing...</div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>
        <script>
            const pdfUrl = "{{ route('documents.file', $document) }}";
            const cleanPdfUrl = "{{ route('documents.cleanPdf', $document) }}";
            const saveUrl = "{{ route('documents.save', $document) }}";
            const processOcrUrl = "{{ route('documents.processOcr', $document) }}";
            const extractionDataUrl = "{{ route('documents.getExtractionData', $document) }}";
            const processFitzUrl = "{{ route('documents.processFitz', $document) }}";
            const fitzExtractionDataUrl = "{{ route('documents.getFitzExtractionData', $document) }}";
            const addBlankPageUrl = "{{ route('documents.addBlankPage', $document) }}";
            const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            let pdfVersion = Date.now();

            // Theme switcher
            const themeToggle = document.getElementById('theme-toggle');
            const body = document.body;
            
            // Load saved theme preference
            const savedTheme = localStorage.getItem('pdfEditorTheme');
            if (savedTheme === 'light') {
                body.classList.add('light-theme');
                themeToggle.textContent = '☀️';
            }
            
            themeToggle.addEventListener('click', () => {
                body.classList.toggle('light-theme');
                const isLight = body.classList.contains('light-theme');
                themeToggle.textContent = isLight ? '☀️' : '🌙';
                localStorage.setItem('pdfEditorTheme', isLight ? 'light' : 'dark');
            });

            // Trigger PyMuPDF extraction on page load (faster, better)
            fetch(processFitzUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                console.log('✓ PyMuPDF extraction started:', data.message);
            })
            .catch(err => console.log('PyMuPDF processing started'));

            // Also trigger OCR as fallback for scanned PDFs
            fetch(processOcrUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                }
            }).catch(err => console.log('OCR processing started'));


            let basePdfUrl = pdfUrl;
            const pdfUrlWithVersion = () => {
                const joiner = basePdfUrl.includes('?') ? '&' : '?';
                return `${basePdfUrl}${joiner}v=${pdfVersion}`;
            };
            
            // Debug flag to control annotation deletion after save
            const DEBUG_KEEP_ANNOTATIONS = true; // Set to false to delete annotations after save
            
            const documentId = "{{ $document->id }}";
            const annotationsStorageKey = `pdf-annotations-${documentId}`;
            const viewer = document.getElementById('viewer');
            const status = document.getElementById('status');
            const zoomLabel = document.getElementById('zoom-label');
            const zoomOutBtn = document.getElementById('zoom-out');
            const zoomInBtn = document.getElementById('zoom-in');
            const loadMoreBtn = document.getElementById('load-more-pages');
            const pageCountLabel = document.getElementById('page-count');
            const pageList = document.getElementById('page-list');
            const pageJumpInput = document.getElementById('page-jump');
            const pageTotalLabel = document.getElementById('page-total');
            const pagePrevBtn = document.getElementById('page-prev');
            const pageNextBtn = document.getElementById('page-next');

            const selectionLabel = document.getElementById('selection-label');
            const selectionControls = document.getElementById('selection-controls');
            const selectedFont = document.getElementById('selected-font');
            const selectedWeight = document.getElementById('selected-weight');
            const selectedSize = document.getElementById('selected-size');
            const selectedBold = document.getElementById('selected-bold');
            const selectedItalic = document.getElementById('selected-italic');
            const selectedUnderline = document.getElementById('selected-underline');
            const selectedColor = document.getElementById('selected-color');
            const selectedColorSwatch = document.getElementById('selected-color-swatch');
            const selectedBg = document.getElementById('selected-bg');
            const selectedBgSwatch = document.getElementById('selected-bg-swatch');
            const selectedAlign = document.getElementById('selected-align');
            const selectedOpacity = document.getElementById('selected-opacity');
            const selectedDelete = document.getElementById('selected-delete');
            const insertX = document.getElementById('insert-x');
            const insertCheckbox = document.getElementById('insert-checkbox');
            const modeText = document.getElementById('mode-text');
            const modeEditText = document.getElementById('mode-edit-text');
            const modeSign = document.getElementById('mode-sign');
            const modeShape = document.getElementById('mode-shape');
            const modeOverlay = document.getElementById('mode-overlay');
            const modeOverlayToggle = document.getElementById('mode-overlay-toggle');
            const saveOverlayBtn = document.getElementById('save-overlay-btn');
            const signatureModal = document.getElementById('signature-modal');
            const signatureCanvas = document.getElementById('signature-canvas');
            const signatureClear = document.getElementById('signature-clear');
            const signatureCancel = document.getElementById('signature-cancel');
            const signatureSave = document.getElementById('signature-save');
            const signatureClose = document.getElementById('signature-close');
            const signatureColor = document.getElementById('signature-color');
            const signatureWidth = document.getElementById('signature-width');
            const signatureWidthLabel = document.getElementById('signature-width-label');
            const signatureTabs = document.querySelectorAll('[data-signature-tab]');
            const signaturePanels = document.querySelectorAll('[data-signature-panel]');
            const signatureFont = document.getElementById('signature-font');
            const signatureText = document.getElementById('signature-text');
            const viewOriginalPdfBtn = document.getElementById('view-original-pdf');
            const saveSpinner = document.getElementById('save-spinner');
            const saveSpinnerText = document.getElementById('save-spinner-text');
            const organizePagesBtn = document.getElementById('organize-pages-btn');
            const organizePagesModal = document.getElementById('organize-pages-modal');
            const organizePagesGrid = document.getElementById('organize-pages-grid');
            const organizeClose = document.getElementById('organize-close');
            const organizeCancel = document.getElementById('organize-cancel');
            const organizeApply = document.getElementById('organize-apply');
            const deletePageBtn = document.getElementById('delete-page-btn');
            const addPageBtn = document.getElementById('add-page-btn');
            const rotatePageBtn = document.getElementById('rotate-page-btn');
            const moveLeftBtn = document.getElementById('move-left-btn');
            const moveRightBtn = document.getElementById('move-right-btn');
            const moveToBtn = document.getElementById('move-to-btn');
            const duplicatePageBtn = document.getElementById('duplicate-page-btn');

            const annotations = [];
            let draggedPageItem = null;
            let organizePageOrder = [];
            let selectedPageItem = null;
            let originalPdfBytes = null;
            let activeEditor = null;
            let selectedAnnotation = null;
            let selectedOverlayField = null;
            let overlaySelectionRange = null;
            let currentScale = 1.2;
            const baseScale = 1.2;
            const defaultTextFont = 'Helvetica';
            const defaultTextSize = 16;

            // Annotations Panel Management
            function updateAnnotationsList() {
                const annotationsList = document.getElementById('annotations-list');
                if (!annotationsList) return;
                
                annotationsList.innerHTML = '';
                
                annotations.forEach((annotation, index) => {
                    const item = document.createElement('div');
                    item.className = 'annotation-item';
                    item.draggable = true;
                    item.dataset.index = index;
                    
                    // Icon based on type
                    let icon = '📝';
                    let title = 'Annotation';
                    if (annotation.type === 'text') {
                        icon = 'T';
                        title = `New Text ${annotations.filter((a, i) => a.type === 'text' && i <= index).length}`;
                    } else if (annotation.type === 'signature') {
                        icon = '✒';
                        title = `New drawing ${annotations.filter((a, i) => a.type === 'signature' && i <= index).length}`;
                    } else if (annotation.type === 'shape') {
                        icon = '▭';
                        title = `New ${annotation.shapeType || 'shape'} ${annotations.filter((a, i) => a.type === 'shape' && i <= index).length}`;
                    } else if (annotation.type === 'image') {
                        icon = '🖼';
                        title = `New Image ${annotations.filter((a, i) => a.type === 'image' && i <= index).length}`;
                    }
                    
                    item.innerHTML = `
                        <div class="annotation-item-icon">${icon}</div>
                        <div class="annotation-item-content">
                            <div class="annotation-item-title">${title}</div>
                            <div class="annotation-item-meta">Page ${annotation.pageIndex + 1}</div>
                        </div>
                        <div class="annotation-item-actions">
                            <button class="annotation-item-action edit" title="Edit" data-index="${index}">✎</button>
                            <button class="annotation-item-action delete" title="Delete" data-index="${index}">🗑</button>
                        </div>
                    `;
                    
                    // Edit button
                    item.querySelector('.edit').addEventListener('click', (e) => {
                        e.stopPropagation();
                        // Scroll to annotation on page
                        const page = document.querySelector(`[data-page-index="${annotation.pageIndex}"]`);
                        if (page) {
                            page.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    });
                    
                    // Delete button
                    item.querySelector('.delete').addEventListener('click', (e) => {
                        e.stopPropagation();
                        if (confirm('Delete this annotation?')) {
                            annotations.splice(index, 1);
                            persistAnnotations();
                            rerenderPdf();
                            updateAnnotationsList();
                        }
                    });
                    
                    // Drag & drop for reordering
                    item.addEventListener('dragstart', (e) => {
                        item.classList.add('dragging');
                        e.dataTransfer.effectAllowed = 'move';
                        e.dataTransfer.setData('text/plain', index);
                    });
                    
                    item.addEventListener('dragend', () => {
                        item.classList.remove('dragging');
                    });
                    
                    item.addEventListener('dragover', (e) => {
                        e.preventDefault();
                        e.dataTransfer.dropEffect = 'move';
                    });
                    
                    item.addEventListener('drop', (e) => {
                        e.preventDefault();
                        const fromIndex = parseInt(e.dataTransfer.getData('text/plain'));
                        const toIndex = parseInt(item.dataset.index);
                        
                        if (fromIndex !== toIndex) {
                            const [moved] = annotations.splice(fromIndex, 1);
                            annotations.splice(toIndex, 0, moved);
                            persistAnnotations();
                            rerenderPdf();
                            updateAnnotationsList();
                        }
                    });
                    
                    annotationsList.appendChild(item);
                });
            }

            // Toggle annotations panel
            document.getElementById('annotations-toggle')?.addEventListener('click', () => {
                const panel = document.getElementById('annotations-panel');
                panel.classList.toggle('collapsed');
            });

            // Remove all annotations
            document.getElementById('remove-all-annotations')?.addEventListener('click', () => {
                if (annotations.length === 0) return;
                if (confirm(`Remove all ${annotations.length} annotations?`)) {
                    annotations.length = 0;
                    persistAnnotations();
                    rerenderPdf();
                    updateAnnotationsList();
                }
            });

            const fontMap = {
                Helvetica: { css: '"Helvetica", Arial, sans-serif', pdf: PDFLib.StandardFonts.Helvetica },
                TimesRoman: { css: '"Times New Roman", Times, serif', pdf: PDFLib.StandardFonts.TimesRoman },
                Courier: { css: '"Courier New", Courier, monospace', pdf: PDFLib.StandardFonts.Courier },
            };
            const pdfFontVariants = {
                Helvetica: {
                    normal: PDFLib.StandardFonts.Helvetica,
                    bold: PDFLib.StandardFonts.HelveticaBold,
                    italic: PDFLib.StandardFonts.HelveticaOblique,
                    boldItalic: PDFLib.StandardFonts.HelveticaBoldOblique,
                },
                TimesRoman: {
                    normal: PDFLib.StandardFonts.TimesRoman,
                    bold: PDFLib.StandardFonts.TimesRomanBold,
                    italic: PDFLib.StandardFonts.TimesRomanItalic,
                    boldItalic: PDFLib.StandardFonts.TimesRomanBoldItalic,
                },
                Courier: {
                    normal: PDFLib.StandardFonts.Courier,
                    bold: PDFLib.StandardFonts.CourierBold,
                    italic: PDFLib.StandardFonts.CourierOblique,
                    boldItalic: PDFLib.StandardFonts.CourierBoldOblique,
                },
            };

            let insertMode = null;
            let toolMode = 'select'; // Start in select mode, user clicks Edit Text to edit
            let signatureDataUrl = null;
            let shapeType = 'circle';
            let shapeStroke = '#000000';
            let shapeStrokeWidth = 2;
            let shapeStrokeTransparentState = false;
            let shapeFill = '#000000';
            let shapeFillTransparentState = true;
            let shapeOpacityValue = 1;
            const pdfTextItems = [];
            let pdfDoc = null;
            let pdfjsDocument = null;
            let totalPages = 0;
            let renderedPages = 0;
            let renderInProgress = false;
            const initialPageBatch = 6;
            const pageBatchSize = 4;
            
            // Overlay editor state
            let overlayEditorActive = false;
            let overlayExtractionData = null;
            let overlayPdfDoc = null;  // Track overlay PDF for cleanup
            let overlayEditedFields = new Map();
            let overlayPersistedEdits = new Map();
            let overlayLoadToken = 0;
            let overlayResizingField = null;
            let overlayResizeStart = { x: 0, y: 0, width: 0, height: 0, left: 0, top: 0 };
            let overlayResizePosition = '';
            const overlayLoadedFonts = new Set();
            const overlayEditsStorageKey = `pdf-overlay-edits-${documentId}`;
            let overlayRendered = false;
            let overlayUndoStack = [];
            let overlayRedoStack = [];
            const overlayUndoBtn = document.getElementById('overlay-undo');
            const overlayRedoBtn = document.getElementById('overlay-redo');
            const textMeasureCtx = document.createElement('canvas').getContext('2d');

            // Cleanup function to destroy overlay PDF and release memory
            function cleanupOverlayPdf() {
                if (overlayPdfDoc) {
                    overlayPdfDoc.destroy();
                    overlayPdfDoc = null;
                }
            }

            const measureAnnotationTextWidth = (text, fontSizePx, fontFamily, fontWeight, fontStyle) => {
                const weight = fontWeight || '400';
                const style = fontStyle || 'normal';
                textMeasureCtx.font = `${style} ${weight} ${fontSizePx}px ${fontFamily}`;
                const metrics = textMeasureCtx.measureText(text || '');
                return metrics.width || 0;
            };
            
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';

            function serializeAnnotations() {
                return annotations.map((annotation) => {
                    const { element, ...rest } = annotation;
                    return rest;
                });
            }

            function persistAnnotations() {
                try {
                    if (!annotations.length) {
                        sessionStorage.removeItem(annotationsStorageKey);
                        return;
                    }
                    sessionStorage.setItem(annotationsStorageKey, JSON.stringify(serializeAnnotations()));
                } catch (err) {
                    console.warn('Failed to store annotations', err);
                }
            }

            function loadAnnotationsFromStorage() {
                try {
                    const stored = sessionStorage.getItem(annotationsStorageKey);
                    if (!stored) {
                        return;
                    }
                    const parsed = JSON.parse(stored);
                    if (!Array.isArray(parsed)) {
                        return;
                    }
                    parsed.forEach((annotation) => {
                        const normalized = {
                            ...annotation,
                            pageIndex: Number(annotation.pageIndex) || 0,
                            pdfX: Number(annotation.pdfX) || 0,
                            pdfY: Number(annotation.pdfY) || 0,
                            fontSize: Number(annotation.fontSize) || 12,
                            pdfWidth: Number(annotation.pdfWidth) || 0,
                            pdfHeight: Number(annotation.pdfHeight) || 0,
                        };
                        normalizeTextAnnotation(normalized);
                        annotations.push(normalized);
                    });
                } catch (err) {
                    console.warn('Failed to read annotations', err);
                }
            }

            async function loadPdf() {
                try {
                    setStatus('Loading PDF...', '');
                    loadAnnotationsFromStorage();
                    await rerenderPdf();
                    setStatus('PDF loaded successfully.', 'ok');
                } catch (err) {
                    setStatus('Failed to load PDF: ' + err.message, 'err');
                }
            }

            async function loadOriginalPdfBytes() {
                if (originalPdfBytes) {
                    return;
                }
                const response = await fetch(pdfUrlWithVersion());
                originalPdfBytes = await response.arrayBuffer();
            }

            function setStatus(message, type) {
                status.textContent = message;
                status.className = 'status' + (type ? ' ' + type : '');
            }

            const setSaveSpinner = (visible, message) => {
                if (!saveSpinner) {
                    return;
                }
                if (saveSpinnerText && message) {
                    saveSpinnerText.textContent = message;
                }
                saveSpinner.classList.toggle('active', Boolean(visible));
                saveSpinner.setAttribute('aria-hidden', visible ? 'false' : 'true');
            };

            function setSelection(annotation) {
                if (selectedAnnotation && selectedAnnotation.element) {
                    selectedAnnotation.element.classList.remove('selected');
                    // Hide resize handles and action bar from previously selected shape
                    const prevHandles = selectedAnnotation.element.querySelectorAll('.shape-resize-handle');
                    prevHandles.forEach(h => h.style.display = 'none');
                    const prevRotateHandle = selectedAnnotation.element.querySelector('.rotate-handle');
                    if (prevRotateHandle) {
                        prevRotateHandle.style.display = 'none';
                    }
                    const prevActionBar = selectedAnnotation.element.querySelector('.shape-action-bar');
                    if (prevActionBar) {
                        prevActionBar.style.display = 'none';
                    }
                }
                selectedAnnotation = annotation;
                if (annotation) {
                    clearOverlaySelection();
                }
                if (selectedAnnotation && selectedAnnotation.element) {
                    selectedAnnotation.element.classList.add('selected');
                    // Show resize handles and action bar for selected shape
                    if (selectedAnnotation.type === 'shape') {
                        const handles = selectedAnnotation.element.querySelectorAll('.shape-resize-handle');
                        handles.forEach(h => h.style.display = 'block');
                        const rotateHandle = selectedAnnotation.element.querySelector('.rotate-handle');
                        if (rotateHandle) {
                            rotateHandle.style.display = 'flex';
                        }
                        const actionBar = selectedAnnotation.element.querySelector('.shape-action-bar');
                        if (actionBar) {
                            actionBar.style.display = 'flex';
                        }
                    }
                }
                updateSelectionBar();
            }

            function updateTextLayerVisibility() {
                // Keep extracted text hidden in Add Text mode; only show it in Edit Text mode.
                viewer.classList.toggle('text-editing', toolMode === 'edit-text');
                viewer.classList.toggle('edit-text-mode', toolMode === 'edit-text');
            }

            function updateSelectionBar() {
                if (!selectedAnnotation && !selectedOverlayField) {
                    selectionLabel.textContent = 'No text selected';
                    selectionControls.classList.add('disabled');
                    selectedFont.value = 'Helvetica';
                    selectedSize.value = 16;
                    selectedFont.disabled = true;
                    selectedSize.disabled = true;
                    selectedBold.disabled = true;
                    selectedItalic.disabled = true;
                    selectedUnderline.disabled = true;
                    selectedColor.disabled = true;
                    selectedBg.disabled = true;
                    selectedAlign.disabled = true;
                    selectedOpacity.disabled = true;
                    selectedDelete.disabled = true;
                    return;
                }

                selectionLabel.textContent = 'Selected text';
                selectionControls.classList.remove('disabled');

                if (selectedAnnotation) {
                    const isText = selectedAnnotation.type === 'text' || !selectedAnnotation.type;
                    selectedFont.disabled = !isText;
                    selectedWeight.disabled = !isText;
                    selectedSize.disabled = !isText;
                    selectedBold.disabled = !isText;
                    selectedItalic.disabled = !isText;
                    selectedUnderline.disabled = !isText;
                    selectedColor.disabled = !isText;
                    selectedBg.disabled = !isText;
                    selectedAlign.disabled = !isText;
                    selectedOpacity.disabled = !isText;
                    selectedDelete.disabled = false;
                    if (isText) {
                        selectedFont.value = selectedAnnotation.fontFamily;
                        const annoWeight = String(selectedAnnotation.fontWeight);
                        selectedWeight.value = ['100','200','300','400','500','600','700','800','900'].includes(annoWeight) ? annoWeight : (annoWeight === 'bold' ? '700' : '400');
                        selectedSize.value = Math.round(selectedAnnotation.fontSize * currentScale);
                        selectedColor.value = selectedAnnotation.textColor || '#111111';
                        const background = selectedAnnotation.backgroundColor || 'transparent';
                        selectedBg.value = background === 'transparent' ? '#ffffff' : background;
                        selectedAlign.value = selectedAnnotation.textAlign || 'left';
                        const opacityValue = String(selectedAnnotation.opacity ?? 1);
                        if ([...selectedOpacity.options].some((option) => option.value === opacityValue)) {
                            selectedOpacity.value = opacityValue;
                        } else {
                            selectedOpacity.value = '1';
                        }
                        selectedBold.classList.toggle('active', selectedAnnotation.fontWeight === '700' || selectedAnnotation.fontWeight === 'bold');
                        selectedItalic.classList.toggle('active', selectedAnnotation.fontStyle === 'italic');
                        selectedUnderline.classList.toggle('active', Boolean(selectedAnnotation.underline));
                    } else {
                        selectionLabel.textContent = 'Selected item';
                        selectedFont.value = 'Helvetica';
                        selectedSize.value = 16;
                        selectedBold.classList.remove('active');
                        selectedItalic.classList.remove('active');
                        selectedUnderline.classList.remove('active');
                    }
                    return;
                }

                if (selectedOverlayField) {
                    const textEl = getOverlayTextElement(selectedOverlayField);
                    const styles = window.getComputedStyle(textEl);
                    selectedFont.disabled = false;
                    selectedWeight.disabled = false;
                    selectedSize.disabled = false;
                    selectedBold.disabled = false;
                    selectedItalic.disabled = false;
                    selectedUnderline.disabled = false;
                    selectedColor.disabled = false;
                    selectedBg.disabled = false;
                    selectedAlign.disabled = false;
                    selectedOpacity.disabled = false;
                    selectedDelete.disabled = false;

                    const mappedFont = mapFontFamilyToKey(styles.fontFamily);
                    selectedFont.value = mappedFont;
                    const weightValue = parseInt(styles.fontWeight, 10);
                    const normalizedWeight = Number.isFinite(weightValue) ? String(Math.round(weightValue / 100) * 100) : '400';
                    selectedWeight.value = ['100','200','300','400','500','600','700','800','900'].includes(normalizedWeight) ? normalizedWeight : '400';
                    selectedSize.value = Math.round(parseFloat(styles.fontSize) || 16);
                    selectedColor.value = colorToHex(styles.color) || '#111111';
                    const bgColor = colorToHex(styles.backgroundColor);
                    selectedBg.value = bgColor && bgColor !== 'transparent' ? bgColor : '#ffffff';
                    selectedAlign.value = styles.textAlign || 'left';
                    const opacityValue = String(parseFloat(styles.opacity || '1'));
                    if ([...selectedOpacity.options].some((option) => option.value === opacityValue)) {
                        selectedOpacity.value = opacityValue;
                    } else {
                        selectedOpacity.value = '1';
                    }
                    selectedBold.classList.toggle('active', Number.isFinite(weightValue) ? weightValue >= 600 : styles.fontWeight === 'bold');
                    selectedItalic.classList.toggle('active', styles.fontStyle === 'italic' || styles.fontStyle === 'oblique');
                    const decoration = styles.textDecorationLine || '';
                    selectedUnderline.classList.toggle('active', decoration.includes('underline'));
                }
            }

            function clearOverlaySelection() {
                if (selectedOverlayField) {
                    selectedOverlayField.classList.remove('selected');
                }
                selectedOverlayField = null;
            }

            function setOverlaySelection(field) {
                if (!field) {
                    return;
                }
                if (selectedAnnotation) {
                    setSelection(null);
                }
                clearOverlaySelection();
                selectedOverlayField = field;
                selectedOverlayField.classList.add('selected');
                updateSelectionBar();
            }

            function getOverlayTextElement(field) {
                return field.querySelector('[contenteditable]') || field;
            }

            function applyOverlayStyle(updateFn) {
                if (!selectedOverlayField) {
                    return;
                }
                const textEl = getOverlayTextElement(selectedOverlayField);
                updateFn(textEl, selectedOverlayField);
                const wordSpans = textEl.querySelectorAll('span');
                if (wordSpans.length) {
                    wordSpans.forEach((span) => updateFn(span, selectedOverlayField));
                }
                updateSelectionBar();
            }

            function applyOverlayColorToSelection(color) {
                if (!selectedOverlayField) {
                    return false;
                }
                const textEl = getOverlayTextElement(selectedOverlayField);
                const selection = window.getSelection();
                let range = null;
                if (selection && selection.rangeCount > 0) {
                    range = selection.getRangeAt(0);
                }
                if ((!range || range.collapsed) && overlaySelectionRange) {
                    range = overlaySelectionRange;
                }
                if (!range) {
                    return false;
                }
                if (!textEl.contains(range.commonAncestorContainer)) {
                    return false;
                }
                if (range.collapsed) {
                    return false;
                }
                const span = document.createElement('span');
                span.style.color = color;
                try {
                    range.surroundContents(span);
                } catch (err) {
                    // Fallback for complex selections
                    const contents = range.extractContents();
                    span.appendChild(contents);
                    range.insertNode(span);
                }
                if (selection) {
                    selection.removeAllRanges();
                }
                overlaySelectionRange = null;
                return true;
            }

            function applyOverlayBgToSelection(color) {
                if (!selectedOverlayField) {
                    return false;
                }
                const textEl = getOverlayTextElement(selectedOverlayField);
                const selection = window.getSelection();
                let range = null;
                if (selection && selection.rangeCount > 0) {
                    range = selection.getRangeAt(0);
                }
                if ((!range || range.collapsed) && overlaySelectionRange) {
                    range = overlaySelectionRange;
                }
                if (!range) {
                    return false;
                }
                if (!textEl.contains(range.commonAncestorContainer)) {
                    return false;
                }
                if (range.collapsed) {
                    return false;
                }
                const span = document.createElement('span');
                span.style.backgroundColor = color;
                try {
                    range.surroundContents(span);
                } catch (err) {
                    // Fallback for complex selections
                    const contents = range.extractContents();
                    span.appendChild(contents);
                    range.insertNode(span);
                }
                if (selection) {
                    selection.removeAllRanges();
                }
                overlaySelectionRange = null;
                return true;
            }

            function applyOverlayStyleToSelection(styleProperty, styleValue) {
                if (!selectedOverlayField) {
                    return false;
                }
                const textEl = getOverlayTextElement(selectedOverlayField);
                const selection = window.getSelection();
                let range = null;
                if (selection && selection.rangeCount > 0) {
                    range = selection.getRangeAt(0);
                }
                if ((!range || range.collapsed) && overlaySelectionRange) {
                    range = overlaySelectionRange;
                }
                if (!range) {
                    return false;
                }
                if (!textEl.contains(range.commonAncestorContainer)) {
                    return false;
                }
                if (range.collapsed) {
                    return false;
                }
                const span = document.createElement('span');
                span.style[styleProperty] = styleValue;
                try {
                    range.surroundContents(span);
                } catch (err) {
                    // Fallback for complex selections
                    const contents = range.extractContents();
                    span.appendChild(contents);
                    range.insertNode(span);
                }
                if (selection) {
                    selection.removeAllRanges();
                }
                overlaySelectionRange = null;
                return true;
            }

            function mapFontFamilyToKey(fontFamily) {
                if (!fontFamily) {
                    return 'Helvetica';
                }
                
                const lower = fontFamily.toLowerCase().replace(/['"]/g, '').trim();
                
                // Get the first font in the font-family list (before any comma)
                const firstFont = lower.split(',')[0].trim();
                
                // Check if this font exists in the dropdown
                const fontSelect = document.getElementById('selected-font');
                if (fontSelect) {
                    // Try exact match first (case-insensitive)
                    for (let option of fontSelect.options) {
                        if (option.value && option.value.toLowerCase() === firstFont) {
                            return option.value;
                        }
                    }
                    
                    // Try partial match (font family name might be part of a longer name)
                    for (let option of fontSelect.options) {
                        if (option.value && 
                            !option.disabled && 
                            (option.value.toLowerCase().includes(firstFont) || 
                             firstFont.includes(option.value.toLowerCase()))) {
                            return option.value;
                        }
                    }
                }
                
                // Fallback to default mappings
                if (lower.includes('times')) {
                    return 'TimesRoman';
                }
                if (lower.includes('courier')) {
                    return 'Courier';
                }
                return 'Helvetica';
            }

            function colorToHex(color) {
                if (!color) {
                    return '#111111';
                }
                if (color === 'transparent') {
                    return 'transparent';
                }
                if (color.startsWith('#')) {
                    return color.length === 4
                        ? `#${color[1]}${color[1]}${color[2]}${color[2]}${color[3]}${color[3]}`
                        : color;
                }
                const match = color.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)/);
                if (!match) {
                    return '#111111';
                }
                if (match[4] !== undefined && parseFloat(match[4]) === 0) {
                    return 'transparent';
                }
                const r = parseInt(match[1], 10).toString(16).padStart(2, '0');
                const g = parseInt(match[2], 10).toString(16).padStart(2, '0');
                const b = parseInt(match[3], 10).toString(16).padStart(2, '0');
                return `#${r}${g}${b}`;
            }

            document.addEventListener('selectionchange', () => {
                if (!selectedOverlayField) {
                    overlaySelectionRange = null;
                    return;
                }
                const selection = window.getSelection();
                if (!selection || selection.rangeCount === 0) {
                    overlaySelectionRange = null;
                    return;
                }
                const range = selection.getRangeAt(0);
                const textEl = getOverlayTextElement(selectedOverlayField);
                if (range && !range.collapsed && textEl.contains(range.commonAncestorContainer)) {
                    overlaySelectionRange = range.cloneRange();
                }
            });

            function applyAnnotationStyle(annotation) {
                if (!annotation || !annotation.element) {
                    return;
                }
                if (annotation.type === 'text' || !annotation.type) {
                    const fontFamily = fontMap[annotation.fontFamily]?.css || 'inherit';
                    const fontSizePx = annotation.fontSize * currentScale;
                    annotation.element.style.fontFamily = fontFamily;
                    annotation.element.style.fontSize = fontSizePx + 'px';
                    annotation.element.style.textAlign = annotation.textAlign || 'left';
                    const span = annotation.element.querySelector('.annotation-text');
                    if (span) {
                        span.textContent = annotation.text;
                        span.style.color = annotation.textColor || '#111111';
                        span.style.backgroundColor = annotation.backgroundColor || 'transparent';
                        span.style.fontWeight = annotation.fontWeight || 'normal';
                        span.style.fontStyle = annotation.fontStyle || 'normal';
                        span.style.textDecoration = annotation.underline ? 'underline' : 'none';
                        span.style.opacity = annotation.opacity ?? 1;
                    }
                    const width = measureAnnotationTextWidth(annotation.text, fontSizePx, fontFamily, annotation.fontWeight, annotation.fontStyle);
                    let translateX = 0;
                    if (annotation.textAlign === 'center') {
                        translateX = -width / 2;
                    } else if (annotation.textAlign === 'right') {
                        translateX = -width;
                    }
                    annotation.element.style.transform = `translateX(${translateX}px)`;
                }
            }

            function normalizeTextAnnotation(annotation) {
                if (!annotation || annotation.type === 'signature' || annotation.type === 'shape') {
                    return;
                }
                annotation.type = annotation.type || 'text';
                annotation.fontFamily = annotation.fontFamily || 'Helvetica';
                annotation.textColor = annotation.textColor || '#111111';
                annotation.backgroundColor = annotation.backgroundColor || 'transparent';
                annotation.fontWeight = annotation.fontWeight || 'normal';
                annotation.fontStyle = annotation.fontStyle || 'normal';
                annotation.underline = Boolean(annotation.underline);
                annotation.textAlign = annotation.textAlign || 'left';
                annotation.opacity = typeof annotation.opacity === 'number' ? annotation.opacity : 1;
            }

            function addPageThumbnail(pageNumber, canvas) {
                if (!pageList) {
                    return;
                }
                const thumb = document.createElement('div');
                thumb.className = 'page-thumb';
                thumb.dataset.pageIndex = String(pageNumber - 1);

                const thumbCanvas = document.createElement('canvas');
                const thumbWidth = 160;
                const scale = thumbWidth / canvas.width;
                thumbCanvas.width = Math.round(canvas.width * scale);
                thumbCanvas.height = Math.round(canvas.height * scale);
                const thumbCtx = thumbCanvas.getContext('2d');
                thumbCtx.drawImage(canvas, 0, 0, thumbCanvas.width, thumbCanvas.height);

                const label = document.createElement('span');
                label.textContent = String(pageNumber);

                thumb.appendChild(thumbCanvas);
                thumb.appendChild(label);

                thumb.addEventListener('click', () => {
                    pageList.querySelectorAll('.page-thumb.active').forEach((item) => {
                        item.classList.remove('active');
                    });
                    thumb.classList.add('active');
                    const target = viewer.querySelector(
                        `.page[data-page-index="${pageNumber - 1}"], .overlay-page[data-page-number="${pageNumber}"]`
                    );
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });

                if (pageNumber === 1) {
                    thumb.classList.add('active');
                }
                pageList.appendChild(thumb);
            }

            function placeSignatureOnFirstPage() {
                const wrapper = viewer.querySelector('.page') || viewer.querySelector('.overlay-page');
                if (!wrapper) {
                    const fallbackWidth = 180 / currentScale;
                    const fallbackHeight = 60 / currentScale;
                    const annotation = {
                        type: 'signature',
                        dataUrl: signatureDataUrl,
                        pageIndex: 0,
                        pdfX: 72,
                        pdfY: 72,
                        pdfWidth: fallbackWidth,
                        pdfHeight: fallbackHeight,
                    };
                    annotations.push(annotation);
                    persistAnnotations();
                    updateAnnotationsList();
                    rerenderPdf();
                    return;
                }

                const canvas = wrapper.querySelector('canvas');
                const overlay = wrapper.querySelector('.overlay');
                if (!canvas || !overlay) {
                    return;
                }

                const pdfPageWidth = canvas.width / currentScale;
                const pdfPageHeight = canvas.height / currentScale;
                const targetWidthPx = Math.min(220, canvas.width * 0.35);
                const sigWidth = targetWidthPx / currentScale;
                const sigHeight = (targetWidthPx * 0.35) / currentScale;
                const pdfX = Math.max(12 / currentScale, (pdfPageWidth - sigWidth) / 2);
                const pdfY = Math.max(12 / currentScale, (pdfPageHeight - sigHeight) / 2);

                const annotation = {
                    type: 'signature',
                    dataUrl: signatureDataUrl,
                    pageIndex: parseInt(wrapper.dataset.pageIndex || '0', 10) || 0,
                    pdfX,
                    pdfY,
                    pdfWidth: sigWidth,
                    pdfHeight: sigHeight,
                };

                annotations.push(annotation);
                persistAnnotations();
                updateAnnotationsList();
                addAnnotationElement(wrapper, annotation, {
                    scale: currentScale,
                    canvasHeight: canvas.height,
                });
                setSelection(annotation);
                setStatus('Signature placed. Click Save to keep changes.', 'ok');
            }

            function sanitizeTextForPdf(text) {
                if (!text) {
                    return text;
                }
                let sanitized = text
                    .replace(/✕/g, 'X')
                    .replace(/×/g, 'x')
                    .replace(/☐/g, '[ ]')
                    .replace(/☑/g, '[x]')
                    .replace(/☒/g, '[x]');
                sanitized = sanitized.replace(/[^\x20-\x7E]/g, '?');
                return sanitized;
            }

            function generateSessionId() {
                return 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            }

            function generateAnnotationId() {
                return 'ann_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            }

            async function saveAnnotationToDatabase(annotation) {
                try {
                    const sessionId = localStorage.getItem('pdf_session_id') || generateSessionId();
                    localStorage.setItem('pdf_session_id', sessionId);
                    
                    const saveUrl = `{{ route('documents.saveAnnotations', $document) }}`;
                    const response = await fetch(saveUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            annotations: [annotation],
                            session_id: sessionId,
                            user_email: null,
                            annotation_id: annotation.id,
                        }),
                    });
                    
                    if (response.ok) {
                        console.log('Annotation saved to database:', annotation.id);
                    } else {
                        console.error('Failed to save annotation to database');
                    }
                } catch (error) {
                    console.error('Error saving annotation to database:', error);
                }
            }

            async function rerenderPdf() {
                closeTextEditPopup();
                viewer.innerHTML = '';
                try {
                    await renderPdf();
                } catch (err) {
                    setStatus('Failed to load PDF.', 'err');
                }
            }

            function removeActiveEditor() {
                if (!activeEditor) {
                    return;
                }

                try {
                    // Check if the element still exists in the DOM
                    if (activeEditor.parentNode && activeEditor.parentNode.contains(activeEditor)) {
                        activeEditor.parentNode.removeChild(activeEditor);
                    } else if (activeEditor.isConnected) {
                        // Element is in DOM but not in expected parent, use remove()
                        activeEditor.remove();
                    }
                } catch (e) {
                    // Element was already removed or doesn't exist, ignore the error
                    console.warn('Editor element already removed:', e);
                }
                
                activeEditor = null;
            }

            // Close any open text edit popup
            function closeTextEditPopup() {
                const popup = document.querySelector('.text-edit-popup');
                if (popup) {
                    popup.remove();
                }
            }

            // Open text edit popup like PDFe.com
            function openTextEditPopup(textItem, span, overlay) {
                closeTextEditPopup();
                
                const popup = document.createElement('div');
                popup.className = 'text-edit-popup';
                
                const input = document.createElement('input');
                input.type = 'text';
                input.value = textItem.text;
                input.style.fontSize = span.style.fontSize;
                
                const actions = document.createElement('div');
                actions.className = 'popup-actions';
                
                const saveBtn = document.createElement('button');
                saveBtn.className = 'save-btn';
                saveBtn.textContent = 'Save';
                
                const cancelBtn = document.createElement('button');
                cancelBtn.className = 'cancel-btn';
                cancelBtn.textContent = 'Cancel';
                
                actions.appendChild(cancelBtn);
                actions.appendChild(saveBtn);
                popup.appendChild(input);
                popup.appendChild(actions);
                
                // Position popup at the text location
                popup.style.left = span.style.left;
                popup.style.top = span.style.top;
                
                overlay.appendChild(popup);
                input.focus();
                input.select();
                
                // Save handler
                const saveEdit = () => {
                    const newText = input.value;
                    if (newText !== textItem.originalText) {
                        textItem.text = newText;
                        textItem.modified = true;
                        span.textContent = newText;
                        span.classList.add('modified');
                        setStatus(`Text changed to "${newText}". Click Save PDF to keep changes.`, 'ok');
                        updateEditTextBanner();
                    }
                    closeTextEditPopup();
                };
                
                // Cancel handler
                const cancelEdit = () => {
                    closeTextEditPopup();
                };
                
                saveBtn.addEventListener('click', saveEdit);
                cancelBtn.addEventListener('click', cancelEdit);
                
                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        saveEdit();
                    } else if (e.key === 'Escape') {
                        e.preventDefault();
                        cancelEdit();
                    }
                });
            }

            function buildTextLayerFromPdf(page, viewport, textLayer, pageIndex, overlay) {
                return page.getTextContent().then((textContent) => {
                    textContent.items.forEach((item, idx) => {
                        if (!item.str || !item.str.trim()) {
                            return;
                        }
                        const tx = pdfjsLib.Util.transform(viewport.transform, item.transform);
                        const x = tx[4];
                        const y = tx[5];
                        const fontHeight = Math.hypot(tx[2], tx[3]) || item.height || 10;
                        const width = item.width ? item.width * currentScale : (item.str.length * fontHeight * 0.6);

                        const span = document.createElement('span');
                        span.className = 'pdf-text';
                        span.textContent = item.str;
                        span.style.left = x + 'px';
                        span.style.top = (viewport.height - y) + 'px';
                        span.style.fontSize = fontHeight + 'px';
                        span.style.height = fontHeight + 'px';
                        span.style.lineHeight = fontHeight + 'px';
                        span.style.width = width + 'px';

                        const textItem = {
                            id: `pdf-${pageIndex}-${idx}`,
                            pageIndex,
                            pdfX: x / currentScale,
                            pdfY: y / currentScale,
                            fontSize: fontHeight / currentScale,
                            width: width / currentScale,
                            originalText: item.str,
                            text: item.str,
                            modified: false,
                            element: span,
                        };
                        pdfTextItems.push(textItem);

                        // Click handler - opens popup editor like PDFe.com
                        span.addEventListener('click', (e) => {
                            if (toolMode !== 'edit-text') return;
                            e.stopPropagation();
                            openTextEditPopup(textItem, span, overlay);
                        });

                        textLayer.appendChild(span);
                    });
                });
            }

            function startInlineEdit(wrapper, annotation) {
                if (annotation.type !== 'text') {
                    return;
                }
                removeActiveEditor();
                const overlay = wrapper.querySelector('.overlay');
                const x = annotation.pdfX * currentScale;
                const y = (annotation.pdfY * currentScale);
                const fontSizePx = Math.round(annotation.fontSize * currentScale);

                const editor = document.createElement('input');
                editor.type = 'text';
                editor.className = 'text-editor';
                editor.style.left = x + 'px';
                editor.style.top = (overlay.clientHeight - y) + 'px';
                editor.style.fontSize = fontSizePx + 'px';
                editor.style.fontFamily = fontMap[annotation.fontFamily]?.css || 'inherit';
                editor.style.fontWeight = annotation.fontWeight || 'normal';
                editor.style.fontStyle = annotation.fontStyle || 'normal';
                editor.style.textDecoration = annotation.underline ? 'underline' : 'none';
                editor.style.color = annotation.textColor || '#111111';
                editor.style.backgroundColor = annotation.backgroundColor || '#ffffff';
                editor.style.opacity = annotation.opacity ?? 1;
                editor.style.textAlign = annotation.textAlign || 'left';
                editor.value = annotation.text;
                const originalText = annotation.text; // Store original text

                activeEditor = editor;

                let finished = false;
                const finishEditing = () => {
                    if (finished) {
                        return;
                    }
                    finished = true;
                    const text = editor.value.trim();
                    // Only update if text actually changed
                    if (text && text !== originalText) {
                        annotation.text = text;
                        applyAnnotationStyle(annotation);
                        setSelection(annotation);
                        persistAnnotations();
                        setStatus('Text updated. Click Save to keep changes.', 'ok');
                    } else if (text === originalText) {
                        // Text unchanged, just close editor without persisting
                        setSelection(annotation);
                    }
                    // Only remove if this editor is still the active one
                    if (activeEditor === editor) {
                        removeActiveEditor();
                    }
                };

                editor.addEventListener('blur', finishEditing);
                editor.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        finishEditing();
                    } else if (e.key === 'Escape') {
                        removeActiveEditor();
                    }
                });

                overlay.appendChild(editor);
                editor.focus();
            }

            function addAnnotationElement(wrapper, annotation, pageInfo) {
                const label = document.createElement('div');
                label.className = 'annotation';
                
                // Set z-index based on position in annotations array for proper layering
                const zIndex = annotations.indexOf(annotation);
                if (zIndex >= 0) {
                    label.style.zIndex = String(zIndex);
                }
                
                let textSpan = null;
                let signatureImg = null;
                let shapeSvg = null;
                if (annotation.type === 'signature') {
                    signatureImg = document.createElement('img');
                    signatureImg.src = annotation.dataUrl;
                    signatureImg.alt = 'Signature';
                    signatureImg.style.display = 'block';
                } else if (annotation.type === 'shape') {
                    shapeSvg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                    shapeSvg.setAttribute('width', '100%');
                    shapeSvg.setAttribute('height', '100%');
                    shapeSvg.setAttribute('viewBox', '0 0 100 100');
                    shapeSvg.setAttribute('preserveAspectRatio', 'none');
                    shapeSvg.style.display = 'block';
                    const strokeColor = annotation.strokeTransparent ? 'transparent' : annotation.strokeColor;
                    const fillColor = annotation.fillTransparent ? 'transparent' : annotation.fillColor;
                    if (annotation.shapeType === 'circle' || annotation.shapeType === 'ellipse') {
                        const ellipse = document.createElementNS('http://www.w3.org/2000/svg', 'ellipse');
                        ellipse.setAttribute('cx', '50');
                        ellipse.setAttribute('cy', '50');
                        ellipse.setAttribute('rx', '48');
                        ellipse.setAttribute('ry', '48');
                        ellipse.setAttribute('fill', fillColor);
                        ellipse.setAttribute('stroke', strokeColor);
                        ellipse.setAttribute('stroke-width', String(annotation.strokeWidth));
                        ellipse.setAttribute('opacity', String(annotation.opacity));
                        shapeSvg.appendChild(ellipse);
                    } else if (annotation.shapeType === 'triangle') {
                        const triangle = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
                        triangle.setAttribute('points', '50 5, 95 95, 5 95');
                        triangle.setAttribute('fill', fillColor);
                        triangle.setAttribute('stroke', strokeColor);
                        triangle.setAttribute('stroke-width', String(annotation.strokeWidth));
                        triangle.setAttribute('opacity', String(annotation.opacity));
                        shapeSvg.appendChild(triangle);
                    } else if (annotation.shapeType === 'x') {
                        const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                        const line1 = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                        line1.setAttribute('x1', '15');
                        line1.setAttribute('y1', '15');
                        line1.setAttribute('x2', '85');
                        line1.setAttribute('y2', '85');
                        line1.setAttribute('stroke', strokeColor);
                        line1.setAttribute('stroke-width', String(annotation.strokeWidth));
                        line1.setAttribute('stroke-linecap', 'round');
                        line1.setAttribute('opacity', String(annotation.opacity));
                        const line2 = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                        line2.setAttribute('x1', '85');
                        line2.setAttribute('y1', '15');
                        line2.setAttribute('x2', '15');
                        line2.setAttribute('y2', '85');
                        line2.setAttribute('stroke', strokeColor);
                        line2.setAttribute('stroke-width', String(annotation.strokeWidth));
                        line2.setAttribute('stroke-linecap', 'round');
                        line2.setAttribute('opacity', String(annotation.opacity));
                        g.appendChild(line1);
                        g.appendChild(line2);
                        shapeSvg.appendChild(g);
                    } else if (annotation.shapeType === 'checkmark') {
                        const polyline = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
                        polyline.setAttribute('points', '15 50, 40 75, 85 15');
                        polyline.setAttribute('fill', 'none');
                        polyline.setAttribute('stroke', strokeColor);
                        polyline.setAttribute('stroke-width', String(annotation.strokeWidth));
                        polyline.setAttribute('stroke-linecap', 'round');
                        polyline.setAttribute('stroke-linejoin', 'round');
                        polyline.setAttribute('opacity', String(annotation.opacity));
                        shapeSvg.appendChild(polyline);
                    } else if (annotation.shapeType === 'star') {
                        const star = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
                        star.setAttribute('points', '50,5 61,38 95,38 68,58 79,91 50,71 21,91 32,58 5,38 39,38');
                        star.setAttribute('fill', fillColor);
                        star.setAttribute('stroke', strokeColor);
                        star.setAttribute('stroke-width', String(annotation.strokeWidth));
                        star.setAttribute('stroke-linejoin', 'round');
                        star.setAttribute('opacity', String(annotation.opacity));
                        shapeSvg.appendChild(star);
                    } else if (annotation.shapeType === 'polygon') {
                        const polygon = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
                        polygon.setAttribute('points', '50,5 90,27 90,73 50,95 10,73 10,27');
                        polygon.setAttribute('fill', fillColor);
                        polygon.setAttribute('stroke', strokeColor);
                        polygon.setAttribute('stroke-width', String(annotation.strokeWidth));
                        polygon.setAttribute('stroke-linejoin', 'round');
                        polygon.setAttribute('opacity', String(annotation.opacity));
                        shapeSvg.appendChild(polygon);
                    } else if (annotation.shapeType === 'arrow') {
                        const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                        const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                        line.setAttribute('x1', '10');
                        line.setAttribute('y1', '50');
                        line.setAttribute('x2', '80');
                        line.setAttribute('y2', '50');
                        line.setAttribute('stroke', strokeColor);
                        line.setAttribute('stroke-width', String(annotation.strokeWidth));
                        line.setAttribute('stroke-linecap', 'round');
                        line.setAttribute('opacity', String(annotation.opacity));
                        const arrowHead = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
                        arrowHead.setAttribute('points', '65,35 80,50 65,65');
                        arrowHead.setAttribute('fill', 'none');
                        arrowHead.setAttribute('stroke', strokeColor);
                        arrowHead.setAttribute('stroke-width', String(annotation.strokeWidth));
                        arrowHead.setAttribute('stroke-linecap', 'round');
                        arrowHead.setAttribute('stroke-linejoin', 'round');
                        arrowHead.setAttribute('opacity', String(annotation.opacity));
                        g.appendChild(line);
                        g.appendChild(arrowHead);
                        shapeSvg.appendChild(g);
                    } else {
                        const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                        rect.setAttribute('x', '5');
                        rect.setAttribute('y', '5');
                        rect.setAttribute('width', '90');
                        rect.setAttribute('height', '90');
                        rect.setAttribute('fill', fillColor);
                        rect.setAttribute('stroke', strokeColor);
                        rect.setAttribute('stroke-width', String(annotation.strokeWidth));
                        rect.setAttribute('opacity', String(annotation.opacity));
                        shapeSvg.appendChild(rect);
                    }
                } else {
                    textSpan = document.createElement('span');
                    textSpan.className = 'annotation-text';
                    textSpan.textContent = annotation.text;
                }

                const deleteBtn = document.createElement('button');
                deleteBtn.innerHTML = '×';
                deleteBtn.title = 'Delete this text';
                deleteBtn.style.cssText = 'position: absolute; top: -8px; right: -8px; width: 24px; height: 24px; border-radius: 50%; background: var(--danger); color: white; border: 2px solid var(--bg); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: bold; line-height: 1; z-index: 10; opacity: 0; transition: opacity 0.2s;';
                deleteBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    wrapper.querySelector('.overlay').removeChild(label);
                    const idx = annotations.indexOf(annotation);
                    if (idx >= 0) {
                        annotations.splice(idx, 1);
                        if (selectedAnnotation === annotation) {
                            selectedAnnotation = null;
                        }
                        updateSelectionBar();
                        persistAnnotations();
                        updateAnnotationsList();
                        setStatus('Text deleted. Click Save to keep changes.', 'ok');
                    }
                });

                if (textSpan) {
                    label.appendChild(textSpan);
                }
                if (signatureImg) {
                    label.appendChild(signatureImg);
                }
                if (shapeSvg) {
                    label.appendChild(shapeSvg);
                    annotation.shapeSvg = shapeSvg; // Store reference for rotation
                    label.style.padding = '0';
                    label.style.border = 'none';
                    label.style.background = 'transparent';
                    label.style.cursor = 'pointer';
                    
                    // Add 6 resize handles around the shape
                    const handlePositions = [
                        { class: 'nw', cursor: 'nw-resize', top: '-6px', left: '-6px' },
                        { class: 'n', cursor: 'n-resize', top: '-6px', left: '50%', transform: 'translateX(-50%)' },
                        { class: 'ne', cursor: 'ne-resize', top: '-6px', right: '-6px' },
                        { class: 'sw', cursor: 'sw-resize', bottom: '-6px', left: '-6px' },
                        { class: 's', cursor: 's-resize', bottom: '-6px', left: '50%', transform: 'translateX(-50%)' },
                        { class: 'se', cursor: 'se-resize', bottom: '-6px', right: '-6px' }
                    ];
                    
                    handlePositions.forEach(pos => {
                        const handle = document.createElement('div');
                        handle.className = `shape-resize-handle ${pos.class}`;
                        handle.style.cssText = `position: absolute; width: 12px; height: 12px; background: #4dd0a8; border: 2px solid #0b1320; border-radius: 50%; cursor: ${pos.cursor}; display: none; z-index: 15;`;
                        if (pos.top) handle.style.top = pos.top;
                        if (pos.bottom) handle.style.bottom = pos.bottom;
                        if (pos.left) handle.style.left = pos.left;
                        if (pos.right) handle.style.right = pos.right;
                        if (pos.transform) handle.style.transform = pos.transform;
                        
                        // Handle resize
                        handle.addEventListener('pointerdown', (e) => {
                            e.stopPropagation();
                            e.preventDefault();
                            
                            label.dataset.resizing = 'true';
                            const startX = e.clientX;
                            const startY = e.clientY;
                            const startWidth = label.offsetWidth;
                            const startHeight = label.offsetHeight;
                            const startLeft = label.offsetLeft;
                            const startTop = label.offsetTop;
                            const direction = pos.class;
                            
                            const onResizeMove = (moveEvent) => {
                                const deltaX = moveEvent.clientX - startX;
                                const deltaY = moveEvent.clientY - startY;
                                
                                let newWidth = startWidth;
                                let newHeight = startHeight;
                                let newLeft = startLeft;
                                let newTop = startTop;
                                
                                // Calculate based on handle direction
                                if (direction.includes('e')) {
                                    newWidth = Math.max(30, startWidth + deltaX);
                                }
                                if (direction.includes('w')) {
                                    newWidth = Math.max(30, startWidth - deltaX);
                                    newLeft = startLeft + deltaX;
                                    if (newWidth === 30) newLeft = startLeft + startWidth - 30;
                                }
                                if (direction.includes('s')) {
                                    newHeight = Math.max(30, startHeight + deltaY);
                                }
                                if (direction.includes('n')) {
                                    newHeight = Math.max(30, startHeight - deltaY);
                                    newTop = startTop + deltaY;
                                    if (newHeight === 30) newTop = startTop + startHeight - 30;
                                }
                                
                                // Update the label
                                label.style.width = newWidth + 'px';
                                label.style.height = newHeight + 'px';
                                label.style.left = newLeft + 'px';
                                label.style.top = newTop + 'px';
                                
                                // Update annotation
                                annotation.pdfWidth = newWidth / pageInfo.scale;
                                annotation.pdfHeight = newHeight / pageInfo.scale;
                                annotation.pdfX = newLeft / pageInfo.scale;
                                annotation.pdfY = (pageInfo.canvasHeight - newTop) / pageInfo.scale - annotation.pdfHeight;
                            };
                            
                            const onResizeUp = () => {
                                delete label.dataset.resizing;
                                window.removeEventListener('pointermove', onResizeMove);
                                window.removeEventListener('pointerup', onResizeUp);
                                persistAnnotations();
                                // Save updated annotation to database
                                saveAnnotationToDatabase(annotation);
                                setStatus('Shape resized. Click Save to keep changes.', 'ok');
                            };
                            
                            window.addEventListener('pointermove', onResizeMove);
                            window.addEventListener('pointerup', onResizeUp);
                        });
                        
                        label.appendChild(handle);
                    });
                    
                    // Create action bar with 4 buttons
                    const actionBar = document.createElement('div');
                    actionBar.className = 'shape-action-bar';
                    actionBar.style.cssText = 'position: absolute; top: -40px; left: 50%; transform: translateX(-50%); background: rgba(11, 19, 32, 0.95); border: 1px solid rgba(255,255,255,0.2); border-radius: 8px; padding: 6px; display: none; gap: 6px; z-index: 20; box-shadow: 0 4px 12px rgba(0,0,0,0.4);';
                    
                    const createActionButton = (icon, title, color) => {
                        const btn = document.createElement('button');
                        btn.innerHTML = icon;
                        btn.title = title;
                        btn.style.cssText = `width: 32px; height: 32px; background: rgba(77, 208, 168, 0.15); border: 1px solid rgba(77, 208, 168, 0.3); color: ${color || 'var(--accent)'}; border-radius: 6px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 16px; transition: all 0.2s;`;
                        btn.addEventListener('mouseenter', () => {
                            btn.style.background = 'rgba(77, 208, 168, 0.25)';
                            btn.style.transform = 'scale(1.05)';
                        });
                        btn.addEventListener('mouseleave', () => {
                            btn.style.background = 'rgba(77, 208, 168, 0.15)';
                            btn.style.transform = 'scale(1)';
                        });
                        return btn;
                    };
                    
                    // Send to front
                    const toFrontBtn = createActionButton('⬆', 'Send to front');
                    toFrontBtn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const idx = annotations.indexOf(annotation);
                        if (idx >= 0) {
                            annotations.splice(idx, 1);
                            annotations.push(annotation);
                            persistAnnotations();
                            rerenderPdf();
                            setStatus('Shape moved to front.', 'ok');
                        }
                    });
                    
                    // Send to back
                    const toBackBtn = createActionButton('⬇', 'Send to back');
                    toBackBtn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const idx = annotations.indexOf(annotation);
                        if (idx >= 0) {
                            annotations.splice(idx, 1);
                            annotations.unshift(annotation);
                            persistAnnotations();
                            rerenderPdf();
                            setStatus('Shape moved to back.', 'ok');
                        }
                    });
                    
                    // Change color
                    const colorBtn = createActionButton('🎨', 'Change color');
                    colorBtn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        openShapeColorPicker(annotation, shapeSvg, label);
                    });
                    
                    // Delete
                    const deleteShapeBtn = createActionButton('🗑', 'Delete shape', '#ff6b6b');
                    deleteShapeBtn.style.borderColor = 'rgba(255, 107, 107, 0.3)';
                    deleteShapeBtn.style.background = 'rgba(255, 107, 107, 0.15)';
                    deleteShapeBtn.addEventListener('mouseenter', () => {
                        deleteShapeBtn.style.background = 'rgba(255, 107, 107, 0.25)';
                    });
                    deleteShapeBtn.addEventListener('mouseleave', () => {
                        deleteShapeBtn.style.background = 'rgba(255, 107, 107, 0.15)';
                    });
                    deleteShapeBtn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const overlay = wrapper.querySelector('.overlay');
                        overlay.removeChild(label);
                        const idx = annotations.indexOf(annotation);
                        if (idx >= 0) {
                            annotations.splice(idx, 1);
                            if (selectedAnnotation === annotation) {
                                selectedAnnotation = null;
                            }
                            updateSelectionBar();
                            persistAnnotations();
                            updateAnnotationsList();
                            setStatus('Shape deleted. Click Save to keep changes.', 'ok');
                        }
                    });
                    
                    actionBar.appendChild(toFrontBtn);
                    actionBar.appendChild(toBackBtn);
                    actionBar.appendChild(colorBtn);
                    actionBar.appendChild(deleteShapeBtn);
                    label.appendChild(actionBar);
                    // Hide by default, show when selected
                    actionBar.style.display = 'none';
                    
                    // Create rotate handle (separate from action bar)
                    const rotateHandle = document.createElement('div');
                    rotateHandle.className = 'rotate-handle';
                    rotateHandle.innerHTML = '🔄';
                    rotateHandle.style.cssText = `
                        position: absolute;
                        width: 24px;
                        height: 24px;
                        background: rgba(59, 130, 246, 0.9);
                        border: 2px solid #0b1320;
                        border-radius: 50%;
                        cursor: grab;
                        display: none;
                        align-items: center;
                        justify-content: center;
                        font-size: 14px;
                        z-index: 20;
                        user-select: none;
                    `;
                    
                    // Position rotate handle 15% outside bounding box (including handles) at bottom center
                    const updateRotateHandlePosition = () => {
                        const width = label.offsetWidth;
                        const height = label.offsetHeight;
                        // Account for resize handles (6px radius + 6px offset = 12px total)
                        const handleMargin = 12;
                        const boundingHeight = height + (handleMargin * 2);
                        const distance = (boundingHeight / 2) * 1.15; // 15% beyond bounding box
                        rotateHandle.style.top = `${height / 2 + distance}px`; // From center, move down
                        rotateHandle.style.left = `${width / 2 - 12}px`; // Center horizontally (12 = half of handle width)
                    };
                    updateRotateHandlePosition();
                    
                    let isRotating = false;
                    let rotateStartAngle = 0;
                    let rotateStartRotation = 0;
                    let rotateStartX = 0;
                    let rotateStartY = 0;
                    
                    rotateHandle.addEventListener('mousedown', (e) => {
                        e.stopPropagation();
                        e.preventDefault();
                        isRotating = true;
                        label.dataset.rotating = 'true'; // Lock shape from moving
                        rotateHandle.style.cursor = 'grabbing';
                        rotateHandle.style.background = 'rgba(59, 130, 246, 1)';
                        
                        // Store initial position
                        rotateStartX = annotation.pdfX;
                        rotateStartY = annotation.pdfY;
                        
                        // Get shape center in page coordinates
                        const shapeWidth = label.offsetWidth;
                        const shapeHeight = label.offsetHeight;
                        const shapeLeft = label.offsetLeft;
                        const shapeTop = label.offsetTop;
                        const centerX = shapeLeft + shapeWidth / 2;
                        const centerY = shapeTop + shapeHeight / 2;
                        
                        // Calculate initial angle from center to handle
                        const dx = e.pageX - centerX;
                        const dy = e.pageY - centerY;
                        rotateStartAngle = Math.atan2(dy, dx) * (180 / Math.PI);
                        rotateStartRotation = annotation.rotation || 0;
                        
                        setStatus('Drag to rotate shape...', 'ok');
                    });
                    
                    const handleRotateMove = (e) => {
                        if (!isRotating) return;
                        
                        // Lock position to original coordinates
                        annotation.pdfX = rotateStartX;
                        annotation.pdfY = rotateStartY;
                        
                        // Get shape center in page coordinates
                        const shapeWidth = label.offsetWidth;
                        const shapeHeight = label.offsetHeight;
                        const shapeLeft = label.offsetLeft;
                        const shapeTop = label.offsetTop;
                        const centerX = shapeLeft + shapeWidth / 2;
                        const centerY = shapeTop + shapeHeight / 2;
                        
                        // Calculate current angle from center to mouse
                        const dx = e.pageX - centerX;
                        const dy = e.pageY - centerY;
                        const currentAngle = Math.atan2(dy, dx) * (180 / Math.PI);
                        
                        // Calculate rotation delta and apply
                        let newRotation = rotateStartRotation + (currentAngle - rotateStartAngle);
                        
                        // Normalize to 0-360
                        while (newRotation < 0) newRotation += 360;
                        while (newRotation >= 360) newRotation -= 360;
                        
                        annotation.rotation = newRotation;
                        if (annotation.shapeSvg) {
                            annotation.shapeSvg.style.transform = `rotate(${annotation.rotation}deg)`;
                            annotation.shapeSvg.style.transformOrigin = 'center';
                        }
                        
                        // Update handle position to follow mouse angle
                        const width = label.offsetWidth;
                        const height = label.offsetHeight;
                        const handleMargin = 12;
                        const boundingWidth = width + (handleMargin * 2);
                        const boundingHeight = height + (handleMargin * 2);
                        const maxDimension = Math.max(boundingWidth, boundingHeight);
                        const distance = (maxDimension / 2) * 1.15; // 15% beyond bounding box
                        const angleRad = currentAngle * (Math.PI / 180);
                        const handleX = Math.cos(angleRad) * distance;
                        const handleY = Math.sin(angleRad) * distance;
                        rotateHandle.style.left = `${width / 2 + handleX - 12}px`;
                        rotateHandle.style.top = `${height / 2 + handleY - 12}px`;
                    };
                    
                    const handleRotateEnd = () => {
                        if (!isRotating) return;
                        isRotating = false;
                        delete label.dataset.rotating; // Unlock shape
                        rotateHandle.style.cursor = 'grab';
                        rotateHandle.style.background = 'rgba(59, 130, 246, 0.9)';
                        updateRotateHandlePosition(); // Reset to default position
                        persistAnnotations();
                        saveAnnotationToDatabase(annotation);
                        setStatus('Shape rotated. Click Save to keep changes.', 'ok');
                    };
                    
                    document.addEventListener('mousemove', handleRotateMove);
                    document.addEventListener('mouseup', handleRotateEnd);
                    
                    label.appendChild(rotateHandle);
                }
                label.appendChild(deleteBtn);
                if (annotation.type !== 'signature' && annotation.type !== 'shape') {
                    label.style.fontFamily = fontMap[annotation.fontFamily]?.css || 'inherit';
                    // Show delete button on hover
                    label.addEventListener('mouseenter', () => {
                        deleteBtn.style.opacity = '1';
                    });
                    label.addEventListener('mouseleave', () => {
                        deleteBtn.style.opacity = '0';
                    });
                } else if (annotation.type === 'shape') {
                    // Hide delete button for shapes (they have their own in action bar)
                    deleteBtn.style.display = 'none';
                }

                const overlay = wrapper.querySelector('.overlay');
                const updatePosition = () => {
                    const x = annotation.pdfX * pageInfo.scale;
                    if (annotation.type === 'signature') {
                        const width = annotation.pdfWidth * pageInfo.scale;
                        const height = annotation.pdfHeight * pageInfo.scale;
                        label.style.left = x + 'px';
                        label.style.top = (pageInfo.canvasHeight - (annotation.pdfY + annotation.pdfHeight) * pageInfo.scale) + 'px';
                        if (signatureImg) {
                            signatureImg.style.width = width + 'px';
                            signatureImg.style.height = height + 'px';
                        }
                    } else if (annotation.type === 'shape') {
                        const width = annotation.pdfWidth * pageInfo.scale;
                        const height = annotation.pdfHeight * pageInfo.scale;
                        const y = pageInfo.canvasHeight - (annotation.pdfY + annotation.pdfHeight) * pageInfo.scale;
                        label.style.left = x + 'px';
                        label.style.top = y + 'px';
                        label.style.width = width + 'px';
                        label.style.height = height + 'px';
                        // Apply rotation to SVG only, not the label
                        if (annotation.rotation && annotation.shapeSvg) {
                            annotation.shapeSvg.style.transform = `rotate(${annotation.rotation}deg)`;
                            annotation.shapeSvg.style.transformOrigin = 'center';
                        }
                    } else {
                        const y = pageInfo.canvasHeight - annotation.pdfY * pageInfo.scale;
                        label.style.left = x + 'px';
                        label.style.top = y + 'px';
                        label.style.fontSize = (annotation.fontSize * pageInfo.scale) + 'px';
                    }
                };
                updatePosition();

                let dragStart = null;
                let dragMoved = false;

                const onPointerMove = (event) => {
                    if (!dragStart) {
                        return;
                    }
                    dragMoved = true;
                    const rect = overlay.getBoundingClientRect();
                    const x = event.clientX - rect.left - dragStart.offsetX;
                    const y = event.clientY - rect.top - dragStart.offsetY;
                    const maxX = rect.width - label.offsetWidth;
                    const maxY = rect.height - label.offsetHeight;
                    const clampedX = Math.max(0, Math.min(maxX, x));
                    const clampedY = Math.max(0, Math.min(maxY, y));
                    annotation.pdfX = clampedX / pageInfo.scale;
                    if (annotation.type === 'signature') {
                        annotation.pdfY = (pageInfo.canvasHeight - clampedY) / pageInfo.scale - annotation.pdfHeight;
                    } else if (annotation.type === 'shape') {
                        annotation.pdfY = (pageInfo.canvasHeight - clampedY) / pageInfo.scale - annotation.pdfHeight;
                    } else {
                        annotation.pdfY = (pageInfo.canvasHeight - clampedY) / pageInfo.scale;
                    }
                    label.style.left = clampedX + 'px';
                    label.style.top = clampedY + 'px';
                };

                const onPointerUp = () => {
                    if (!dragStart) {
                        return;
                    }
                    label.classList.remove('dragging');
                    if (annotation.type === 'shape') {
                        label.style.cursor = 'pointer';
                    }
                    dragStart = null;
                    window.removeEventListener('pointermove', onPointerMove);
                    window.removeEventListener('pointerup', onPointerUp);
                    if (dragMoved) {
                        persistAnnotations();
                        // Save updated annotation to database
                        saveAnnotationToDatabase(annotation);
                        setStatus('Text moved. Click Save to keep changes.', 'ok');
                    } else {
                        setSelection(annotation);
                    }
                };

                label.addEventListener('dblclick', (event) => {
                    if (event.target === deleteBtn) {
                        return;
                    }
                    event.preventDefault();
                    startInlineEdit(wrapper, annotation);
                });

                label.addEventListener('pointerdown', (event) => {
                    if (event.target === deleteBtn) {
                        return;
                    }
                    // Don't start dragging if clicking on rotate handle
                    if (event.target.classList.contains('rotate-handle')) {
                        return;
                    }
                    // Don't start dragging if shape is being rotated or resized
                    if (label.dataset.rotating || label.dataset.resizing) {
                        return;
                    }
                    
                    // For shapes: check if in ACTIVE state (action bar visible = selected)
                    if (annotation.type === 'shape') {
                        const actionBar = label.querySelector('.shape-action-bar');
                        const isActive = actionBar && actionBar.style.display === 'flex';
                        
                        if (!isActive) {
                            // Shape is INACTIVE - just select it, don't start dragging
                            event.preventDefault();
                            setSelection(annotation);
                            return;
                        }
                        // Shape is ACTIVE - allow dragging to proceed
                    }
                    
                    event.preventDefault();
                    dragMoved = false;
                    const rect = label.getBoundingClientRect();
                    dragStart = {
                        offsetX: event.clientX - rect.left,
                        offsetY: event.clientY - rect.top,
                    };
                    label.classList.add('dragging');
                    if (annotation.type === 'shape') {
                        label.style.cursor = 'move';
                    }
                    window.addEventListener('pointermove', onPointerMove);
                    window.addEventListener('pointerup', onPointerUp);
                });

                annotation.element = label;
                normalizeTextAnnotation(annotation);
                applyAnnotationStyle(annotation);
                if (selectedAnnotation === annotation) {
                    label.classList.add('selected');
                }
                overlay.appendChild(label);
            }

            function rerenderAnnotations() {
                // Re-render all annotations in the correct order
                const wrappers = viewer.querySelectorAll('.page-wrapper');
                wrappers.forEach((wrapper, pageIndex) => {
                    const overlay = wrapper.querySelector('.overlay');
                    if (!overlay) return;
                    
                    // Remove all annotation labels
                    const labels = overlay.querySelectorAll('.annotation-label');
                    labels.forEach(label => label.remove());
                    
                    // Re-add them in order
                    const pageAnnotations = annotations.filter(a => a.pageIndex === pageIndex);
                    const pageInfo = pageInfoMap.get(pageIndex);
                    if (pageInfo) {
                        pageAnnotations.forEach(annotation => {
                            addAnnotationElement(wrapper, annotation, pageInfo);
                        });
                    }
                });
                
                // Restore selection
                if (selectedAnnotation) {
                    setSelection(selectedAnnotation);
                }
            }

            async function renderPdf() {
                // Destroy old PDF document to free memory
                if (pdfjsDocument) {
                    pdfjsDocument.destroy();
                    pdfjsDocument = null;
                }
                const loadingTask = pdfjsLib.getDocument(pdfUrlWithVersion());
                pdfjsDocument = await loadingTask.promise;
                const pdf = pdfjsDocument;
                pdfTextItems.length = 0;
                if (pageList) {
                    pageList.innerHTML = '';
                }
                totalPages = pdf.numPages;
                updatePageControls();
                
                for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++) {
                    const page = await pdf.getPage(pageNumber);
                    const viewport = page.getViewport({ scale: currentScale });

                    const wrapper = document.createElement('div');
                    wrapper.className = 'page';
                    wrapper.dataset.pageIndex = pageNumber - 1;

                    const canvas = document.createElement('canvas');
                    const context = canvas.getContext('2d');
                    canvas.width = viewport.width;
                    canvas.height = viewport.height;

                    const overlay = document.createElement('div');
                    overlay.className = 'overlay';

                    wrapper.appendChild(canvas);
                    wrapper.appendChild(overlay);
                    viewer.appendChild(wrapper);

                    const renderContext = { canvasContext: context, viewport };
                    await page.render(renderContext).promise;
                    addPageThumbnail(pageNumber, canvas);

                    const pageInfo = {
                        scale: currentScale,
                        canvasHeight: canvas.height,
                    };

                    annotations
                        .filter((annotation) => annotation.pageIndex === pageNumber - 1)
                        .forEach((annotation) => addAnnotationElement(wrapper, annotation, pageInfo));

                    const textLayer = document.createElement('div');
                    textLayer.className = 'pdf-text-layer';
                    overlay.appendChild(textLayer);

                    // Always build text layer so edit-text mode can work
                    await buildTextLayerFromPdf(page, viewport, textLayer, pageNumber - 1, overlay);

                    overlay.addEventListener('click', (event) => {
                        if (event.target !== overlay) {
                            return;
                        }
                        
                        // Only handle text mode - simple text placement
                        if (toolMode !== 'text') {
                            setSelection(null);
                            removeActiveEditor();
                            return;
                        }

                        const rect = overlay.getBoundingClientRect();
                        const x = event.clientX - rect.left;
                        const y = event.clientY - rect.top;
                        const fontSizePx = Math.max(8, Math.min(48, parseInt(defaultTextSize, 10)));
                        const fontFamily = defaultTextFont;

                        const editor = document.createElement('input');
                        editor.type = 'text';
                        editor.className = 'text-editor';
                        editor.style.left = x + 'px';
                        editor.style.top = y + 'px';
                        editor.style.fontSize = fontSizePx + 'px';
                        editor.style.fontFamily = fontMap[fontFamily]?.css || 'inherit';
                        editor.placeholder = 'Type text here...';

                        activeEditor = editor;

                        let finished = false;
                        const finishEditing = () => {
                            if (finished) {
                                return;
                            }
                            finished = true;
                            const text = editor.value.trim();
                            if (text) {
                                const annotation = {
                                    id: generateAnnotationId(),
                                    text,
                                    pageIndex: pageNumber - 1,
                                    pdfX: x / currentScale,
                                    pdfY: (canvas.height - y) / currentScale,
                                    fontSize: fontSizePx / currentScale,
                                    fontFamily,
                                    type: 'text',
                                    textColor: '#111111',
                                    backgroundColor: 'transparent',
                                    fontWeight: 'normal',
                                    fontStyle: 'normal',
                                    underline: false,
                                    textAlign: 'left',
                                    opacity: 1,
                                };

                                normalizeTextAnnotation(annotation);
                                annotations.push(annotation);
                                persistAnnotations();
                                updateAnnotationsList();
                                addAnnotationElement(wrapper, annotation, pageInfo);
                                // Save to database immediately
                                saveAnnotationToDatabase(annotation);
                                setSelection(annotation);
                                setStatus('Text added. Click Save to keep changes.', 'ok');
                            }
                            removeActiveEditor();
                        };

                        editor.addEventListener('blur', finishEditing);
                        editor.addEventListener('keydown', (e) => {
                            if (e.key === 'Enter') {
                                finishEditing();
                            } else if (e.key === 'Escape') {
                                removeActiveEditor();
                            }
                        });

                        overlay.appendChild(editor);
                        editor.focus();
                    });

                    let drawingShape = null;
                    overlay.addEventListener('pointerdown', (event) => {
                        if (toolMode !== 'shape') {
                            return;
                        }
                        if (event.target !== overlay) {
                            return;
                        }
                        const rect = overlay.getBoundingClientRect();
                        const startX = event.clientX - rect.left;
                        const startY = event.clientY - rect.top;

                        const shapeWrapper = document.createElement('div');
                        shapeWrapper.className = 'annotation';
                        shapeWrapper.style.position = 'absolute';
                        shapeWrapper.style.left = startX + 'px';
                        shapeWrapper.style.top = startY + 'px';
                        shapeWrapper.style.width = '1px';
                        shapeWrapper.style.height = '1px';
                        shapeWrapper.style.padding = '0';
                        shapeWrapper.style.border = 'none';
                        shapeWrapper.style.background = 'transparent';
                        shapeWrapper.style.cursor = 'move';

                        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                        svg.setAttribute('width', '100%');
                        svg.setAttribute('height', '100%');
                        svg.setAttribute('viewBox', '0 0 100 100');
                        svg.setAttribute('preserveAspectRatio', 'none');

                        const strokeColor = shapeStrokeTransparentState ? 'transparent' : shapeStroke;
                        const fillColor = shapeFillTransparentState ? 'transparent' : shapeFill;
                        if (shapeType === 'circle' || shapeType === 'ellipse') {
                            const ellipse = document.createElementNS('http://www.w3.org/2000/svg', 'ellipse');
                            ellipse.setAttribute('cx', '50');
                            ellipse.setAttribute('cy', '50');
                            ellipse.setAttribute('rx', '48');
                            ellipse.setAttribute('ry', '48');
                            ellipse.setAttribute('fill', fillColor);
                            ellipse.setAttribute('stroke', strokeColor);
                            ellipse.setAttribute('stroke-width', String(shapeStrokeWidth));
                            ellipse.setAttribute('opacity', String(shapeOpacityValue));
                            svg.appendChild(ellipse);
                        } else if (shapeType === 'triangle') {
                            const triangle = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
                            triangle.setAttribute('points', '50 5, 95 95, 5 95');
                            triangle.setAttribute('fill', fillColor);
                            triangle.setAttribute('stroke', strokeColor);
                            triangle.setAttribute('stroke-width', String(shapeStrokeWidth));
                            triangle.setAttribute('opacity', String(shapeOpacityValue));
                            svg.appendChild(triangle);
                        } else if (shapeType === 'x') {
                            const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                            const line1 = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                            line1.setAttribute('x1', '15');
                            line1.setAttribute('y1', '15');
                            line1.setAttribute('x2', '85');
                            line1.setAttribute('y2', '85');
                            line1.setAttribute('stroke', strokeColor);
                            line1.setAttribute('stroke-width', String(shapeStrokeWidth));
                            line1.setAttribute('stroke-linecap', 'round');
                            line1.setAttribute('opacity', String(shapeOpacityValue));
                            const line2 = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                            line2.setAttribute('x1', '85');
                            line2.setAttribute('y1', '15');
                            line2.setAttribute('x2', '15');
                            line2.setAttribute('y2', '85');
                            line2.setAttribute('stroke', strokeColor);
                            line2.setAttribute('stroke-width', String(shapeStrokeWidth));
                            line2.setAttribute('stroke-linecap', 'round');
                            line2.setAttribute('opacity', String(shapeOpacityValue));
                            g.appendChild(line1);
                            g.appendChild(line2);
                            svg.appendChild(g);
                        } else if (shapeType === 'checkmark') {
                            const polyline = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
                            polyline.setAttribute('points', '15 50, 40 75, 85 15');
                            polyline.setAttribute('fill', 'none');
                            polyline.setAttribute('stroke', strokeColor);
                            polyline.setAttribute('stroke-width', String(shapeStrokeWidth));
                            polyline.setAttribute('stroke-linecap', 'round');
                            polyline.setAttribute('stroke-linejoin', 'round');
                            polyline.setAttribute('opacity', String(shapeOpacityValue));
                            svg.appendChild(polyline);
                        } else if (shapeType === 'star') {
                            const star = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
                            star.setAttribute('points', '50,5 61,38 95,38 68,58 79,91 50,71 21,91 32,58 5,38 39,38');
                            star.setAttribute('fill', fillColor);
                            star.setAttribute('stroke', strokeColor);
                            star.setAttribute('stroke-width', String(shapeStrokeWidth));
                            star.setAttribute('stroke-linejoin', 'round');
                            star.setAttribute('opacity', String(shapeOpacityValue));
                            svg.appendChild(star);
                        } else if (shapeType === 'polygon') {
                            const polygon = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
                            polygon.setAttribute('points', '50,5 90,27 90,73 50,95 10,73 10,27');
                            polygon.setAttribute('fill', fillColor);
                            polygon.setAttribute('stroke', strokeColor);
                            polygon.setAttribute('stroke-width', String(shapeStrokeWidth));
                            polygon.setAttribute('stroke-linejoin', 'round');
                            polygon.setAttribute('opacity', String(shapeOpacityValue));
                            svg.appendChild(polygon);
                        } else if (shapeType === 'arrow') {
                            const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                            const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                            line.setAttribute('x1', '10');
                            line.setAttribute('y1', '50');
                            line.setAttribute('x2', '80');
                            line.setAttribute('y2', '50');
                            line.setAttribute('stroke', strokeColor);
                            line.setAttribute('stroke-width', String(shapeStrokeWidth));
                            line.setAttribute('stroke-linecap', 'round');
                            line.setAttribute('opacity', String(shapeOpacityValue));
                            const arrowHead = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
                            arrowHead.setAttribute('points', '65,35 80,50 65,65');
                            arrowHead.setAttribute('fill', 'none');
                            arrowHead.setAttribute('stroke', strokeColor);
                            arrowHead.setAttribute('stroke-width', String(shapeStrokeWidth));
                            arrowHead.setAttribute('stroke-linecap', 'round');
                            arrowHead.setAttribute('stroke-linejoin', 'round');
                            arrowHead.setAttribute('opacity', String(shapeOpacityValue));
                            g.appendChild(line);
                            g.appendChild(arrowHead);
                            svg.appendChild(g);
                        } else {
                            const rectShape = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                            rectShape.setAttribute('x', '5');
                            rectShape.setAttribute('y', '5');
                            rectShape.setAttribute('width', '90');
                            rectShape.setAttribute('height', '90');
                            rectShape.setAttribute('fill', fillColor);
                            rectShape.setAttribute('stroke', strokeColor);
                            rectShape.setAttribute('stroke-width', String(shapeStrokeWidth));
                            rectShape.setAttribute('opacity', String(shapeOpacityValue));
                            svg.appendChild(rectShape);
                        }

                        shapeWrapper.appendChild(svg);
                        overlay.appendChild(shapeWrapper);

                        drawingShape = {
                            startX,
                            startY,
                            element: shapeWrapper,
                            pageInfo,
                            svg
                        };
                    });

                    overlay.addEventListener('pointermove', (event) => {
                        if (!drawingShape) {
                            return;
                        }
                        const rect = overlay.getBoundingClientRect();
                        const currentX = event.clientX - rect.left;
                        const currentY = event.clientY - rect.top;
                        const left = Math.min(drawingShape.startX, currentX);
                        const top = Math.min(drawingShape.startY, currentY);
                        const width = Math.max(1, Math.abs(currentX - drawingShape.startX));
                        const height = Math.max(1, Math.abs(currentY - drawingShape.startY));
                        drawingShape.element.style.left = left + 'px';
                        drawingShape.element.style.top = top + 'px';
                        drawingShape.element.style.width = width + 'px';
                        drawingShape.element.style.height = height + 'px';
                    });

                    overlay.addEventListener('pointerup', (event) => {
                        if (!drawingShape) {
                            return;
                        }
                        const rect = drawingShape.element.getBoundingClientRect();
                        const overlayRect = overlay.getBoundingClientRect();
                        const width = rect.width;
                        const height = rect.height;
                        if (width < 6 || height < 6) {
                            drawingShape.element.remove();
                            drawingShape = null;
                            return;
                        }

                        const left = rect.left - overlayRect.left;
                        const top = rect.top - overlayRect.top;
                        const pdfX = left / currentScale;
                        const pdfWidth = width / currentScale;
                        const pdfHeight = height / currentScale;
                        const pdfY = (overlay.clientHeight - top) / currentScale - pdfHeight;

                        const annotation = {
                            id: generateAnnotationId(),
                            type: 'shape',
                            shapeType,
                            strokeColor: shapeStroke,
                            strokeWidth: shapeStrokeWidth,
                            strokeTransparent: shapeStrokeTransparentState,
                            fillColor: shapeFill,
                            fillTransparent: shapeFillTransparentState,
                            opacity: shapeOpacityValue,
                            pageIndex: pageNumber - 1,
                            pdfX,
                            pdfY,
                            pdfWidth,
                            pdfHeight
                        };
                        annotations.push(annotation);
                        persistAnnotations();
                        updateAnnotationsList();
                        addAnnotationElement(wrapper, annotation, pageInfo);
                        // Save to database immediately
                        saveAnnotationToDatabase(annotation);
                        drawingShape.element.remove();
                        drawingShape = null;
                        // Auto-select the newly created shape to show handles and action bar
                        setTimeout(() => {
                            setSelection(annotation);
                            setStatus('Shape added. Click Save to keep changes.', 'ok');
                        }, 10);
                    });
                }
            }

            function resolvePdfFontKey(annotation) {
                const family = annotation.fontFamily || 'Helvetica';
                const variant = pdfFontVariants[family] || pdfFontVariants.Helvetica;
                const isBold = annotation.fontWeight === '700' || annotation.fontWeight === 'bold';
                const isItalic = annotation.fontStyle === 'italic';
                if (isBold && isItalic) {
                    return variant.boldItalic || variant.bold || variant.italic || variant.normal;
                }
                if (isBold) {
                    return variant.bold || variant.normal;
                }
                if (isItalic) {
                    return variant.italic || variant.normal;
                }
                return variant.normal || fontMap[family]?.pdf || PDFLib.StandardFonts.Helvetica;
            }

            async function savePdf() {
                const hasTextEdits = pdfTextItems.some((item) => item.modified);
                if (!annotations.length && !hasTextEdits) {
                    setStatus('No changes to save.', 'err');
                    return;
                }

                // Log what we're about to save
                console.log('=== SAVE PDF - DATA BEING SENT ===');
                console.log('Text Edits (pdfTextItems with modifications):', {
                    count: pdfTextItems.filter(item => item.modified).length,
                    items: pdfTextItems.filter(item => item.modified).map(item => ({
                        pageIndex: item.pageIndex,
                        text: item.text,
                        pdfX: item.pdfX,
                        pdfY: item.pdfY,
                        fontSize: item.fontSize,
                        width: item.width
                    }))
                });
                console.log('Annotations:', {
                    count: annotations.length,
                    items: annotations.map(ann => ({
                        type: ann.type,
                        pageIndex: ann.pageIndex,
                        text: ann.text || undefined,
                        pdfX: ann.pdfX,
                        pdfY: ann.pdfY,
                        pdfWidth: ann.pdfWidth,
                        pdfHeight: ann.pdfHeight,
                        fontSize: ann.fontSize,
                        fontFamily: ann.fontFamily,
                        fontWeight: ann.fontWeight,
                        fontStyle: ann.fontStyle,
                        textColor: ann.textColor,
                        backgroundColor: ann.backgroundColor,
                        opacity: ann.opacity,
                        underline: ann.underline,
                        textAlign: ann.textAlign,
                        shapeType: ann.shapeType,
                        strokeColor: ann.strokeColor,
                        strokeWidth: ann.strokeWidth,
                        strokeTransparent: ann.strokeTransparent,
                        fillColor: ann.fillColor,
                        fillTransparent: ann.fillTransparent,
                        rotation: ann.rotation || 0
                    }))
                });

                setStatus('Saving...', '');
                setSaveSpinner(true, 'Saving and refreshing...');

                // Mark existing annotations as saved in database (don't update annotation_data, just the state)
                if (annotations.length > 0) {
                    try {
                        const sessionId = localStorage.getItem('pdf_session_id');
                        if (sessionId) {
                            const annotationIds = annotations.map(a => a.id).filter(id => id);
                            const markSavedUrl = `{{ route('documents.markAnnotationsSaved', $document) }}`;
                            
                            console.log('=== MARKING ANNOTATIONS AS SAVED ===');
                            console.log('Session ID:', sessionId);
                            console.log('Annotation IDs:', annotationIds);
                            
                            const markSavedResponse = await fetch(markSavedUrl, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': csrfToken,
                                },
                                body: JSON.stringify({
                                    session_id: sessionId,
                                    annotation_ids: annotationIds,
                                }),
                            });
                            
                            if (markSavedResponse.ok) {
                                const result = await markSavedResponse.json();
                                console.log('Annotations marked as saved:', result);
                            } else {
                                console.error('Failed to mark annotations as saved');
                            }
                        }
                    } catch (error) {
                        console.error('Error marking annotations as saved:', error);
                    }
                }

                // Load the CURRENT version of the PDF (not the original), so we keep previous stamps
                const currentPdfUrl = pdfUrlWithVersion();
                const currentPdfResponse = await fetch(currentPdfUrl);
                const currentPdfBytes = await currentPdfResponse.arrayBuffer();
                const pdfDoc = await PDFLib.PDFDocument.load(currentPdfBytes);
                const fontCache = {};

                for (const item of pdfTextItems) {
                    if (!item.modified) {
                        continue;
                    }
                    const page = pdfDoc.getPage(item.pageIndex);
                    const font = await pdfDoc.embedFont(PDFLib.StandardFonts.Helvetica);
                    const safeText = sanitizeTextForPdf(item.text);
                    const height = item.fontSize || 12;
                    const width = item.width || (safeText.length * item.fontSize * 0.5);
                    page.drawRectangle({
                        x: item.pdfX,
                        y: item.pdfY - height,
                        width,
                        height,
                        color: PDFLib.rgb(1, 1, 1),
                        borderWidth: 0,
                    });
                    page.drawText(safeText, {
                        x: item.pdfX,
                        y: item.pdfY - item.fontSize,
                        size: item.fontSize,
                        font,
                        color: PDFLib.rgb(0, 0, 0),
                    });
                    item.modified = false;
                }

                const dataUrlToUint8 = (dataUrl) => {
                    const base64 = dataUrl.split(',')[1];
                    const binary = atob(base64);
                    const bytes = new Uint8Array(binary.length);
                    for (let i = 0; i < binary.length; i++) {
                        bytes[i] = binary.charCodeAt(i);
                    }
                    return bytes;
                };

                const hexToRgb = (hex) => {
                    if (!hex) return PDFLib.rgb(0, 0, 0);
                    const normalized = hex.replace('#', '');
                    const bigint = parseInt(normalized, 16);
                    const r = ((bigint >> 16) & 255) / 255;
                    const g = ((bigint >> 8) & 255) / 255;
                    const b = (bigint & 255) / 255;
                    return PDFLib.rgb(r, g, b);
                };

                for (const annotation of annotations) {
                    const page = pdfDoc.getPage(annotation.pageIndex);
                    if (annotation.type === 'signature') {
                        const pngBytes = dataUrlToUint8(annotation.dataUrl);
                        const pngImage = await pdfDoc.embedPng(pngBytes);
                        page.drawImage(pngImage, {
                            x: annotation.pdfX,
                            y: annotation.pdfY,
                            width: annotation.pdfWidth,
                            height: annotation.pdfHeight,
                        });
                        continue;
                    }
                    if (annotation.type === 'shape') {
                        const strokeColor = annotation.strokeTransparent ? undefined : hexToRgb(annotation.strokeColor);
                        const fillColor = annotation.fillTransparent ? undefined : hexToRgb(annotation.fillColor);
                        
                        // Ensure opacity is in 0-1 range (in case old data has 0-100 range)
                        const opacity = annotation.opacity > 1 ? annotation.opacity / 100 : (annotation.opacity || 1);
                        
                        // Scale stroke width from SVG viewBox (100x100) to PDF units
                        // In SVG, strokeWidth is relative to a 100x100 viewBox
                        // We need to scale it to PDF coordinates based on the smaller dimension
                        const scaleFactor = Math.min(annotation.pdfWidth, annotation.pdfHeight) / 100;
                        const pdfStrokeWidth = (annotation.strokeWidth || 2) * scaleFactor;
                        
                        // Calculate center point for rotation
                        const centerX = annotation.pdfX + annotation.pdfWidth / 2;
                        const centerY = annotation.pdfY + annotation.pdfHeight / 2;
                        const rotation = annotation.rotation || 0;
                        const pdfRotation = rotation;
                        const rotationRad = (pdfRotation * Math.PI) / 180;
                        
                        if ((annotation.shapeType === 'circle' || annotation.shapeType === 'ellipse') && page.drawEllipse) {
                            // Draw fill and border separately to avoid rendering issues
                            const ellipseConfig = {
                                x: centerX,
                                y: centerY,
                                xScale: annotation.pdfWidth / 2,
                                yScale: annotation.pdfHeight / 2,
                                rotate: PDFLib.degrees(pdfRotation),
                            };
                            
                            // Draw fill first if it exists
                            if (fillColor) {
                                page.drawEllipse({
                                    ...ellipseConfig,
                                    color: fillColor,
                                    opacity: opacity,
                                });
                            }
                            
                            // Draw border separately if it exists
                            if (strokeColor && annotation.strokeWidth > 0) {
                                page.drawEllipse({
                                    ...ellipseConfig,
                                    borderColor: strokeColor,
                                    borderWidth: pdfStrokeWidth,
                                    opacity: opacity,
                                    borderOpacity: opacity,
                                });
                            }
                        } else if (annotation.shapeType === 'triangle') {
                            // Triangle - add raw PDF operators via drawText workaround
                            const topX = annotation.pdfX + annotation.pdfWidth / 2;
                            const topY = annotation.pdfY + annotation.pdfHeight;
                            const rightX = annotation.pdfX + annotation.pdfWidth;
                            const rightY = annotation.pdfY;
                            const leftX = annotation.pdfX;
                            const leftY = annotation.pdfY;
                            
                            // Build PDF operators
                            let ops = 'q\n'; // Save graphics state
                            
                            // Apply rotation if needed
                            if (pdfRotation !== 0) {
                                const cos = Math.cos(rotationRad);
                                const sin = Math.sin(rotationRad);
                                // Translate to center, rotate, translate back
                                ops += `1 0 0 1 ${centerX} ${centerY} cm\n`; // Translate to center
                                ops += `${cos} ${-sin} ${sin} ${cos} 0 0 cm\n`; // Rotate
                                ops += `1 0 0 1 ${-centerX} ${-centerY} cm\n`; // Translate back
                            }
                            
                            // Set fill color if exists
                            if (fillColor && !annotation.fillTransparent) {
                                ops += `${fillColor.red} ${fillColor.green} ${fillColor.blue} rg\n`;
                                ops += `${topX} ${topY} m\n`;
                                ops += `${rightX} ${rightY} l\n`;
                                ops += `${leftX} ${leftY} l\n`;
                                ops += `h\nf\n`;
                            }
                            
                            // Set stroke color if exists
                            if (strokeColor && !annotation.strokeTransparent && annotation.strokeWidth > 0) {
                                const lineWidth = pdfStrokeWidth;
                                ops += `${strokeColor.red} ${strokeColor.green} ${strokeColor.blue} RG\n`;
                                ops += `${lineWidth} w\n`;
                                ops += `${topX} ${topY} m\n`;
                                ops += `${rightX} ${rightY} l\n`;
                                ops += `${leftX} ${leftY} l\n`;
                                ops += `h\nS\n`;
                            }
                            
                            ops += 'Q'; // Restore graphics state
                            
                            // Inject into page content stream
                            const contentBytes = new TextEncoder().encode(ops);
                            const contentStream = pdfDoc.context.flateStream(contentBytes);
                            const contentStreamRef = pdfDoc.context.register(contentStream);
                            
                            // Add to page contents
                            const contents = page.node.Contents();
                            const contentsArray = contents?.asArray?.() || (contents ? [contents] : []);
                            page.node.set(PDFLib.PDFName.of('Contents'), pdfDoc.context.obj([...contentsArray, contentStreamRef]));
                        } else if (annotation.shapeType === 'x') {
                            // X shape - two diagonal lines
                            const x = annotation.pdfX;
                            const y = annotation.pdfY;
                            const w = annotation.pdfWidth;
                            const h = annotation.pdfHeight;
                            
                            let ops = 'q\n';
                            
                            if (pdfRotation !== 0) {
                                const cos = Math.cos(rotationRad);
                                const sin = Math.sin(rotationRad);
                                ops += `1 0 0 1 ${centerX} ${centerY} cm\n`;
                                ops += `${cos} ${-sin} ${sin} ${cos} 0 0 cm\n`;
                                ops += `1 0 0 1 ${-centerX} ${-centerY} cm\n`;
                            }
                            
                            if (strokeColor && annotation.strokeWidth > 0) {
                                const lineWidth = pdfStrokeWidth;
                                ops += `${strokeColor.red} ${strokeColor.green} ${strokeColor.blue} RG\n`;
                                ops += `${lineWidth} w\n`;
                                ops += `1 J\n`; // Round line cap
                                // First diagonal
                                ops += `${x + w * 0.15} ${y + h * 0.85} m\n`;
                                ops += `${x + w * 0.85} ${y + h * 0.15} l\nS\n`;
                                // Second diagonal
                                ops += `${x + w * 0.85} ${y + h * 0.85} m\n`;
                                ops += `${x + w * 0.15} ${y + h * 0.15} l\nS\n`;
                            }
                            
                            ops += 'Q';
                            
                            const contentBytes = new TextEncoder().encode(ops);
                            const contentStream = pdfDoc.context.flateStream(contentBytes);
                            const contentStreamRef = pdfDoc.context.register(contentStream);
                            const contents = page.node.Contents();
                            const contentsArray = contents?.asArray?.() || (contents ? [contents] : []);
                            page.node.set(PDFLib.PDFName.of('Contents'), pdfDoc.context.obj([...contentsArray, contentStreamRef]));
                        } else if (annotation.shapeType === 'checkmark') {
                            // Checkmark shape
                            const x = annotation.pdfX;
                            const y = annotation.pdfY;
                            const w = annotation.pdfWidth;
                            const h = annotation.pdfHeight;
                            
                            let ops = 'q\n';
                            
                            if (pdfRotation !== 0) {
                                const cos = Math.cos(rotationRad);
                                const sin = Math.sin(rotationRad);
                                ops += `1 0 0 1 ${centerX} ${centerY} cm\n`;
                                ops += `${cos} ${-sin} ${sin} ${cos} 0 0 cm\n`;
                                ops += `1 0 0 1 ${-centerX} ${-centerY} cm\n`;
                            }
                            
                            if (strokeColor && annotation.strokeWidth > 0) {
                                const lineWidth = pdfStrokeWidth;
                                ops += `${strokeColor.red} ${strokeColor.green} ${strokeColor.blue} RG\n`;
                                ops += `${lineWidth} w\n`;
                                ops += `1 J 1 j\n`; // Round line cap and join
                                ops += `${x + w * 0.15} ${y + h * 0.5} m\n`;
                                ops += `${x + w * 0.4} ${y + h * 0.25} l\n`;
                                ops += `${x + w * 0.85} ${y + h * 0.85} l\nS\n`;
                            }
                            
                            ops += 'Q';
                            
                            const contentBytes = new TextEncoder().encode(ops);
                            const contentStream = pdfDoc.context.flateStream(contentBytes);
                            const contentStreamRef = pdfDoc.context.register(contentStream);
                            const contents = page.node.Contents();
                            const contentsArray = contents?.asArray?.() || (contents ? [contents] : []);
                            page.node.set(PDFLib.PDFName.of('Contents'), pdfDoc.context.obj([...contentsArray, contentStreamRef]));
                        } else if (annotation.shapeType === 'star') {
                            // Star shape
                            const x = annotation.pdfX;
                            const y = annotation.pdfY;
                            const w = annotation.pdfWidth;
                            const h = annotation.pdfHeight;
                            
                            let ops = 'q\n';
                            
                            if (pdfRotation !== 0) {
                                const cos = Math.cos(rotationRad);
                                const sin = Math.sin(rotationRad);
                                ops += `1 0 0 1 ${centerX} ${centerY} cm\n`;
                                ops += `${cos} ${-sin} ${sin} ${cos} 0 0 cm\n`;
                                ops += `1 0 0 1 ${-centerX} ${-centerY} cm\n`;
                            }
                            
                            // Star points (normalized to 0-1 range)
                            const points = [
                                [0.5, 0.95], [0.61, 0.62], [0.95, 0.62], [0.68, 0.42],
                                [0.79, 0.09], [0.5, 0.29], [0.21, 0.09], [0.32, 0.42],
                                [0.05, 0.62], [0.39, 0.62]
                            ];
                            
                            if (fillColor && !annotation.fillTransparent) {
                                ops += `${fillColor.red} ${fillColor.green} ${fillColor.blue} rg\n`;
                                points.forEach((p, i) => {
                                    const px = x + p[0] * w;
                                    const py = y + p[1] * h;
                                    ops += i === 0 ? `${px} ${py} m\n` : `${px} ${py} l\n`;
                                });
                                ops += `h\nf\n`;
                            }
                            
                            if (strokeColor && !annotation.strokeTransparent && annotation.strokeWidth > 0) {
                                const lineWidth = pdfStrokeWidth;
                                ops += `${strokeColor.red} ${strokeColor.green} ${strokeColor.blue} RG\n`;
                                ops += `${lineWidth} w\n`;
                                ops += `1 j\n`; // Round line join
                                points.forEach((p, i) => {
                                    const px = x + p[0] * w;
                                    const py = y + p[1] * h;
                                    ops += i === 0 ? `${px} ${py} m\n` : `${px} ${py} l\n`;
                                });
                                ops += `h\nS\n`;
                            }
                            
                            ops += 'Q';
                            
                            const contentBytes = new TextEncoder().encode(ops);
                            const contentStream = pdfDoc.context.flateStream(contentBytes);
                            const contentStreamRef = pdfDoc.context.register(contentStream);
                            const contents = page.node.Contents();
                            const contentsArray = contents?.asArray?.() || (contents ? [contents] : []);
                            page.node.set(PDFLib.PDFName.of('Contents'), pdfDoc.context.obj([...contentsArray, contentStreamRef]));
                        } else if (annotation.shapeType === 'polygon') {
                            // Hexagon shape
                            const x = annotation.pdfX;
                            const y = annotation.pdfY;
                            const w = annotation.pdfWidth;
                            const h = annotation.pdfHeight;
                            
                            let ops = 'q\n';
                            
                            if (pdfRotation !== 0) {
                                const cos = Math.cos(rotationRad);
                                const sin = Math.sin(rotationRad);
                                ops += `1 0 0 1 ${centerX} ${centerY} cm\n`;
                                ops += `${cos} ${-sin} ${sin} ${cos} 0 0 cm\n`;
                                ops += `1 0 0 1 ${-centerX} ${-centerY} cm\n`;
                            }
                            
                            // Hexagon points
                            const points = [
                                [0.5, 0.95], [0.9, 0.73], [0.9, 0.27],
                                [0.5, 0.05], [0.1, 0.27], [0.1, 0.73]
                            ];
                            
                            if (fillColor && !annotation.fillTransparent) {
                                ops += `${fillColor.red} ${fillColor.green} ${fillColor.blue} rg\n`;
                                points.forEach((p, i) => {
                                    const px = x + p[0] * w;
                                    const py = y + p[1] * h;
                                    ops += i === 0 ? `${px} ${py} m\n` : `${px} ${py} l\n`;
                                });
                                ops += `h\nf\n`;
                            }
                            
                            if (strokeColor && !annotation.strokeTransparent && annotation.strokeWidth > 0) {
                                const lineWidth = annotation.strokeWidth || 2;
                                ops += `${strokeColor.red} ${strokeColor.green} ${strokeColor.blue} RG\n`;
                                ops += `${lineWidth} w\n`;
                                ops += `1 j\n`;
                                points.forEach((p, i) => {
                                    const px = x + p[0] * w;
                                    const py = y + p[1] * h;
                                    ops += i === 0 ? `${px} ${py} m\n` : `${px} ${py} l\n`;
                                });
                                ops += `h\nS\n`;
                            }
                            
                            ops += 'Q';
                            
                            const contentBytes = new TextEncoder().encode(ops);
                            const contentStream = pdfDoc.context.flateStream(contentBytes);
                            const contentStreamRef = pdfDoc.context.register(contentStream);
                            const contents = page.node.Contents();
                            const contentsArray = contents?.asArray?.() || (contents ? [contents] : []);
                            page.node.set(PDFLib.PDFName.of('Contents'), pdfDoc.context.obj([...contentsArray, contentStreamRef]));
                        } else if (annotation.shapeType === 'arrow') {
                            // Arrow shape
                            const x = annotation.pdfX;
                            const y = annotation.pdfY;
                            const w = annotation.pdfWidth;
                            const h = annotation.pdfHeight;
                            
                            let ops = 'q\n';
                            
                            if (pdfRotation !== 0) {
                                const cos = Math.cos(rotationRad);
                                const sin = Math.sin(rotationRad);
                                ops += `1 0 0 1 ${centerX} ${centerY} cm\n`;
                                ops += `${cos} ${-sin} ${sin} ${cos} 0 0 cm\n`;
                                ops += `1 0 0 1 ${-centerX} ${-centerY} cm\n`;
                            }
                            
                            if (strokeColor && annotation.strokeWidth > 0) {
                                const lineWidth = pdfStrokeWidth;
                                ops += `${strokeColor.red} ${strokeColor.green} ${strokeColor.blue} RG\n`;
                                ops += `${lineWidth} w\n`;
                                ops += `1 J 1 j\n`; // Round line cap and join
                                // Arrow line
                                ops += `${x + w * 0.1} ${y + h * 0.5} m\n`;
                                ops += `${x + w * 0.8} ${y + h * 0.5} l\nS\n`;
                                // Arrow head
                                ops += `${x + w * 0.65} ${y + h * 0.65} m\n`;
                                ops += `${x + w * 0.8} ${y + h * 0.5} l\n`;
                                ops += `${x + w * 0.65} ${y + h * 0.35} l\nS\n`;
                            }
                            
                            ops += 'Q';
                            
                            const contentBytes = new TextEncoder().encode(ops);
                            const contentStream = pdfDoc.context.flateStream(contentBytes);
                            const contentStreamRef = pdfDoc.context.register(contentStream);
                            const contents = page.node.Contents();
                            const contentsArray = contents?.asArray?.() || (contents ? [contents] : []);
                            page.node.set(PDFLib.PDFName.of('Contents'), pdfDoc.context.obj([...contentsArray, contentStreamRef]));
                        } else {
                            // Rectangle (rect shape type or fallback)
                            if (pdfRotation !== 0) {
                                // For rotated rectangles, use PDF operators
                                const x = annotation.pdfX;
                                const y = annotation.pdfY;
                                const w = annotation.pdfWidth;
                                const h = annotation.pdfHeight;
                                
                                let ops = 'q\n'; // Save graphics state
                                
                                // Apply rotation
                                const cos = Math.cos(rotationRad);
                                const sin = Math.sin(rotationRad);
                                ops += `1 0 0 1 ${centerX} ${centerY} cm\n`; // Translate to center
                                ops += `${cos} ${-sin} ${sin} ${cos} 0 0 cm\n`; // Rotate
                                ops += `1 0 0 1 ${-centerX} ${-centerY} cm\n`; // Translate back
                                
                                // Draw fill if exists
                                if (fillColor) {
                                    ops += `${fillColor.red} ${fillColor.green} ${fillColor.blue} rg\n`;
                                    ops += `${x} ${y} ${w} ${h} re\nf\n`;
                                }
                                
                                // Draw stroke if exists
                                if (strokeColor && annotation.strokeWidth > 0) {
                                    const lineWidth = pdfStrokeWidth;
                                    ops += `${strokeColor.red} ${strokeColor.green} ${strokeColor.blue} RG\n`;
                                    ops += `${lineWidth} w\n`;
                                    ops += `${x} ${y} ${w} ${h} re\nS\n`;
                                }
                                
                                ops += 'Q'; // Restore graphics state
                                
                                // Inject into page content stream
                                const contentBytes = new TextEncoder().encode(ops);
                                const contentStream = pdfDoc.context.flateStream(contentBytes);
                                const contentStreamRef = pdfDoc.context.register(contentStream);
                                
                                const contents = page.node.Contents();
                                const contentsArray = contents?.asArray?.() || (contents ? [contents] : []);
                                page.node.set(PDFLib.PDFName.of('Contents'), pdfDoc.context.obj([...contentsArray, contentStreamRef]));
                            } else {
                                // Non-rotated rectangles use drawRectangle API
                                if (fillColor) {
                                    page.drawRectangle({
                                        x: annotation.pdfX,
                                        y: annotation.pdfY,
                                        width: annotation.pdfWidth,
                                        height: annotation.pdfHeight,
                                        color: fillColor,
                                        opacity: opacity,
                                    });
                                }
                                if (strokeColor && annotation.strokeWidth > 0) {
                                    page.drawRectangle({
                                        x: annotation.pdfX,
                                        y: annotation.pdfY,
                                        width: annotation.pdfWidth,
                                        height: annotation.pdfHeight,
                                        borderColor: strokeColor,
                                        borderWidth: pdfStrokeWidth,
                                        borderOpacity: opacity,
                                    });
                                }
                            }
                        }
                        continue;
                    }

                    const fontKey = resolvePdfFontKey(annotation);
                    const fontCacheKey = `${annotation.fontFamily}-${fontKey}`;
                    if (!fontCache[fontCacheKey]) {
                        fontCache[fontCacheKey] = await pdfDoc.embedFont(fontKey);
                    }
                    const font = fontCache[fontCacheKey];
                    const safeText = sanitizeTextForPdf(annotation.text);
                    if (safeText !== annotation.text) {
                        annotation.text = safeText;
                        applyAnnotationStyle(annotation);
                        persistAnnotations();
                    }
                    const baselineY = Math.max(0, annotation.pdfY - annotation.fontSize);
                    const textWidth = font.widthOfTextAtSize(annotation.text, annotation.fontSize);
                    let drawX = annotation.pdfX;
                    if (annotation.textAlign === 'center') {
                        drawX -= textWidth / 2;
                    } else if (annotation.textAlign === 'right') {
                        drawX -= textWidth;
                    }
                    const textOpacity = typeof annotation.opacity === 'number' ? annotation.opacity : 1;
                    if (annotation.backgroundColor && annotation.backgroundColor !== 'transparent') {
                        const bgHeight = annotation.fontSize * 1.15;
                        const bgY = baselineY - (annotation.fontSize * 0.25);
                        page.drawRectangle({
                            x: drawX,
                            y: bgY,
                            width: textWidth,
                            height: bgHeight,
                            color: hexToRgb(annotation.backgroundColor),
                            opacity: textOpacity,
                        });
                    }
                    page.drawText(annotation.text, {
                        x: drawX,
                        y: baselineY,
                        size: annotation.fontSize,
                        font,
                        color: hexToRgb(annotation.textColor || '#000000'),
                        opacity: textOpacity,
                    });
                    if (annotation.underline) {
                        const underlineY = baselineY - (annotation.fontSize * 0.12);
                        page.drawLine({
                            start: { x: drawX, y: underlineY },
                            end: { x: drawX + textWidth, y: underlineY },
                            thickness: Math.max(0.5, annotation.fontSize * 0.06),
                            color: hexToRgb(annotation.textColor || '#000000'),
                            opacity: textOpacity,
                        });
                    }
                }

                const pdfBytes = await pdfDoc.save();
                const blob = new Blob([pdfBytes], { type: 'application/pdf' });
                const formData = new FormData();
                formData.append('edited_pdf', blob, 'edited.pdf');

                console.log('=== FINAL DATA BEING SENT TO BACKEND ===');
                console.log('Request URL:', saveUrl);
                console.log('Method:', 'POST');
                console.log('FormData Contents:', {
                    edited_pdf: {
                        name: 'edited.pdf',
                        type: 'application/pdf',
                        size: blob.size + ' bytes'
                    }
                });
                console.log('PDF Byte Size:', pdfBytes.length, 'bytes');
                console.log('=====================================');

                const response = await fetch(saveUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: formData,
                });

                if (response.ok) {
                    setStatus('Saved! Refreshing preview...', 'ok');
                    setSelection(null);
                    
                    // Always clear annotations from UI after save (they're stamped into PDF now)
                    if (DEBUG_KEEP_ANNOTATIONS) {
                        console.log('DEBUG MODE: Annotations kept in database but cleared from UI');
                    }
                    
                    // Clear all annotations data and DOM elements since they're permanently stamped
                    annotations.length = 0;
                    persistAnnotations();
                    updateAnnotationsList(); // Clear annotations menu sidebar
                    
                    // Remove all annotation DOM elements from overlay
                    const allAnnotationElements = document.querySelectorAll('.annotation-label, .shape-resize-handle, .shape-action-bar');
                    allAnnotationElements.forEach(el => el.remove());
                    
                    cleanupOverlayPdf();  // Free memory from overlay PDF
                    overlayEditorActive = false;
                    overlayRendered = false;
                    overlayLoadToken++;
                    overlayExtractionData = null;  // Clear extraction data to force reload
                    basePdfUrl = pdfUrl;
                    viewer.classList.remove('overlay-view-mode');
                    viewer.classList.remove('overlay-hidden');
                    if (saveOverlayBtn) {
                        saveOverlayBtn.style.display = 'none';
                    }
                    viewer.innerHTML = '';
                    pdfVersion = Date.now();
                    try {
                        setSaveSpinner(true, 'Refreshing preview...');
                        await rerenderPdf();
                        setSaveSpinner(false);
                        setStatus('Saved! Preview updated.', 'ok');
                    } catch (error) {
                        console.error('Failed to refresh preview after save:', error);
                        setStatus('Saved, but failed to refresh preview. Please reload.', 'err');
                        setSaveSpinner(false);
                    }
                } else {
                    setSaveSpinner(false);
                    setStatus('Save failed. Please try again.', 'err');
                }
            }


            document.getElementById('save-btn').addEventListener('click', savePdf);
            
            // Overlay save button - saves overlay edits to PDF
            if (saveOverlayBtn) {
                saveOverlayBtn.addEventListener('click', async () => {
                    if (overlayEditedFields.size === 0) {
                        setStatus('No changes to save.', 'err');
                        return;
                    }
                    
                    try {
                        // Collect the edit coordinates BEFORE saving (what we're sending to fitz)
                        const editsForVerification = [];
                        for (const [key, editData] of overlayEditedFields.entries()) {
                            editsForVerification.push({
                                page_number: editData.page_number,
                                original_text: editData.original_text || '',
                                new_text: editData.new_text || '',
                                bbox: editData.bbox || [0, 0, 100, 100],
                                original_bbox: editData.original_bbox || editData.bbox || [0, 0, 100, 100],
                                font_size: editData.font_size || 12
                            });
                        }
                        
                        console.log('=== EDIT COORDINATES BEING SENT TO FITZ ===');
                        console.log(JSON.stringify(editsForVerification, null, 2));
                        
                        // Perform the actual save
                        setSaveSpinner(true, 'Saving overlay edits...');
                        const saved = await saveOverlayEditsIfNeeded();
                        
                        if (saved) {
                            setStatus('Saved! Reloading document...', 'ok');
                            
                            // Exit overlay mode and reload the page to show the final PDF
                            overlayEditorActive = false;
                            overlayRendered = false;
                            viewer.classList.remove('overlay-view-mode');
                            viewer.classList.remove('overlay-hidden');
                            
                            // Clean up overlay resources
                            cleanupOverlayPdf();
                            
                            // Clear all overlay state
                            overlayEditedFields.clear();
                            overlayPersistedEdits.clear();
                            try {
                                sessionStorage.removeItem(overlayEditsStorageKey);
                            } catch (e) {
                                console.warn('Failed to clear sessionStorage:', e);
                            }
                            
                            // Reload the page to show the final saved PDF
                            setTimeout(() => {
                                window.location.reload();
                            }, 500);
                        }
                        setSaveSpinner(false);
                    } catch (error) {
                        console.error('Error saving overlay edits:', error);
                        setStatus('Save failed: ' + error.message, 'err');
                        setSaveSpinner(false);
                    }
                });
            }
            
            document.getElementById('clear-btn').addEventListener('click', () => {
                if (!confirm('Clear all unsaved changes including text edits?')) {
                    return;
                }
                basePdfUrl = pdfUrl;
                pdfVersion = Date.now();
                annotations.length = 0;
                selectedAnnotation = null;
                clearOverlaySelection();
                overlayEditedFields.clear();
                overlayPersistedEdits.clear();
                overlayUndoStack = [];
                overlayRedoStack = [];
                overlayRendered = false;
                cleanupOverlayPdf();  // Free memory from overlay PDF
                overlayEditorActive = false;
                viewer.classList.remove('overlay-view-mode');
                viewer.classList.remove('overlay-hidden');
                if (saveOverlayBtn) {
                    saveOverlayBtn.style.display = 'none';
                }
                updateOverlaySaveButton();
                persistOverlayEdits();
                
                viewer.innerHTML = '';
                updateSelectionBar();
                persistAnnotations();
                updateAnnotationsList();
                rerenderPdf();
                setStatus('Cleared all unsaved changes.', 'ok');
            });

            const isTextSelection = () =>
                (selectedAnnotation && (selectedAnnotation.type === 'text' || !selectedAnnotation.type)) || !!selectedOverlayField;

            selectedFont.addEventListener('change', () => {
                if (!isTextSelection()) {
                    return;
                }
                if (selectedAnnotation) {
                    selectedAnnotation.fontFamily = selectedFont.value;
                    applyAnnotationStyle(selectedAnnotation);
                    persistAnnotations();
                    setStatus('Font updated. Click Save to keep changes.', 'ok');
                    return;
                }
                if (selectedOverlayField) {
                    const selectedFontValue = selectedFont.value;
                    // Check if it's a standard font in fontMap, otherwise use as custom font
                    let cssFamily;
                    if (fontMap[selectedFontValue]) {
                        cssFamily = fontMap[selectedFontValue].css;
                    } else {
                        // Custom font from PDF - use it directly with fallbacks
                        cssFamily = `"${selectedFontValue}", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
                    }
                    
                    // Check if there's an active text selection
                    const selection = window.getSelection();
                    const hasSelection = selection && selection.rangeCount > 0 && !selection.getRangeAt(0).collapsed;
                    
                    if (hasSelection) {
                        // Apply to selection only
                        applyOverlayStyleToSelection('fontFamily', cssFamily);
                    } else {
                        // Apply to entire field
                        applyOverlayStyle((textEl, field) => {
                            textEl.style.fontFamily = cssFamily;
                            field.style.fontFamily = cssFamily;
                            field.dataset.fontFamily = selectedFontValue;
                        });
                    }
                }
            });

            selectedWeight.addEventListener('change', () => {
                if (!isTextSelection()) {
                    return;
                }
                const weightValue = selectedWeight.value;
                if (selectedAnnotation) {
                    selectedAnnotation.fontWeight = weightValue;
                    applyAnnotationStyle(selectedAnnotation);
                    persistAnnotations();
                    setStatus('Font weight updated. Click Save to keep changes.', 'ok');
                    updateSelectionBar();
                    return;
                }
                if (selectedOverlayField) {
                    // Check if there's an active text selection
                    const selection = window.getSelection();
                    const hasSelection = selection && selection.rangeCount > 0 && !selection.getRangeAt(0).collapsed;
                    
                    if (hasSelection) {
                        // Apply to selection only
                        applyOverlayStyleToSelection('fontWeight', weightValue);
                    } else {
                        // Apply to entire field
                        applyOverlayStyle((textEl, field) => {
                            textEl.style.fontWeight = weightValue;
                            field.style.fontWeight = weightValue;
                            field.dataset.fontWeight = weightValue;
                        });
                    }
                }
            });

            selectedSize.addEventListener('change', () => {
                if (!isTextSelection()) {
                    return;
                }
                const sizePx = Math.max(8, Math.min(48, parseInt(selectedSize.value || '16', 10)));
                if (selectedAnnotation) {
                    selectedAnnotation.fontSize = sizePx / currentScale;
                    applyAnnotationStyle(selectedAnnotation);
                    persistAnnotations();
                    setStatus('Size updated. Click Save to keep changes.', 'ok');
                    return;
                }
                if (selectedOverlayField) {
                    applyOverlayStyle((textEl, field) => {
                        textEl.style.fontSize = sizePx + 'px';
                        field.style.fontSize = sizePx + 'px';
                        field.dataset.fontSize = String(sizePx);
                    });
                }
            });

            selectedBold.addEventListener('click', () => {
                if (!isTextSelection()) {
                    return;
                }
                if (selectedAnnotation) {
                    selectedAnnotation.fontWeight = selectedAnnotation.fontWeight === '700' || selectedAnnotation.fontWeight === 'bold'
                        ? 'normal'
                        : '700';
                    applyAnnotationStyle(selectedAnnotation);
                    persistAnnotations();
                    updateSelectionBar();
                    return;
                }
                if (selectedOverlayField) {
                    const isActive = selectedBold.classList.contains('active');
                    applyOverlayStyle((textEl, field) => {
                        const weight = isActive ? 'normal' : '700';
                        textEl.style.fontWeight = weight;
                        field.style.fontWeight = weight;
                        field.dataset.fontWeight = weight;
                    });
                }
            });

            selectedItalic.addEventListener('click', () => {
                if (!isTextSelection()) {
                    return;
                }
                if (selectedAnnotation) {
                    selectedAnnotation.fontStyle = selectedAnnotation.fontStyle === 'italic' ? 'normal' : 'italic';
                    applyAnnotationStyle(selectedAnnotation);
                    persistAnnotations();
                    updateSelectionBar();
                    return;
                }
                if (selectedOverlayField) {
                    const isActive = selectedItalic.classList.contains('active');
                    applyOverlayStyle((textEl, field) => {
                        const style = isActive ? 'normal' : 'italic';
                        textEl.style.fontStyle = style;
                        field.style.fontStyle = style;
                        field.dataset.fontStyle = style;
                    });
                }
            });

            selectedUnderline.addEventListener('click', () => {
                if (!isTextSelection()) {
                    return;
                }
                if (selectedAnnotation) {
                    selectedAnnotation.underline = !selectedAnnotation.underline;
                    applyAnnotationStyle(selectedAnnotation);
                    persistAnnotations();
                    updateSelectionBar();
                    return;
                }
                if (selectedOverlayField) {
                    const isActive = selectedUnderline.classList.contains('active');
                    applyOverlayStyle((textEl, field) => {
                        const decoration = isActive ? 'none' : 'underline';
                        textEl.style.textDecoration = decoration;
                        field.dataset.underline = String(!isActive);
                    });
                }
            });

            selectedColor.addEventListener('input', () => {
                if (!isTextSelection()) {
                    return;
                }
                if (selectedAnnotation) {
                    selectedAnnotation.textColor = selectedColor.value;
                    applyAnnotationStyle(selectedAnnotation);
                    persistAnnotations();
                    return;
                }
                if (selectedOverlayField) {
                    const appliedToSelection = applyOverlayColorToSelection(selectedColor.value);
                    if (!appliedToSelection) {
                        applyOverlayStyle((textEl, field) => {
                            textEl.style.color = selectedColor.value;
                            field.style.color = selectedColor.value;
                            field.dataset.textColor = selectedColor.value;
                        });
                    }
                }
            });

            // Save selection when clicking on the color swatch label (before color picker opens)
            if (selectedColorSwatch) {
                selectedColorSwatch.addEventListener('mousedown', (e) => {
                    if (!selectedOverlayField) {
                        return;
                    }
                    // Prevent blur of textSpan
                    e.preventDefault();
                    
                    const selection = window.getSelection();
                    if (selection && selection.rangeCount > 0) {
                        const range = selection.getRangeAt(0);
                        const textEl = getOverlayTextElement(selectedOverlayField);
                        if (range && !range.collapsed && textEl && textEl.contains(range.commonAncestorContainer)) {
                            overlaySelectionRange = range.cloneRange();
                            console.log('Saved color selection on mousedown:', overlaySelectionRange.toString());
                        }
                    }
                });
            }

            if (selectedBgSwatch) {
                selectedBgSwatch.addEventListener('mousedown', (e) => {
                    if (!selectedOverlayField) {
                        return;
                    }
                    // Prevent blur of textSpan
                    e.preventDefault();
                    
                    const selection = window.getSelection();
                    if (selection && selection.rangeCount > 0) {
                        const range = selection.getRangeAt(0);
                        const textEl = getOverlayTextElement(selectedOverlayField);
                        if (range && !range.collapsed && textEl && textEl.contains(range.commonAncestorContainer)) {
                            overlaySelectionRange = range.cloneRange();
                            console.log('Saved bg selection on mousedown:', overlaySelectionRange.toString());
                        }
                    }
                });
            }

            selectedBg.addEventListener('input', () => {
                if (!isTextSelection()) {
                    return;
                }
                if (selectedAnnotation) {
                    selectedAnnotation.backgroundColor = selectedBg.value;
                    applyAnnotationStyle(selectedAnnotation);
                    persistAnnotations();
                    return;
                }
                if (selectedOverlayField) {
                    const appliedToSelection = applyOverlayBgToSelection(selectedBg.value);
                    if (!appliedToSelection) {
                        applyOverlayStyle((textEl, field) => {
                            textEl.style.backgroundColor = selectedBg.value;
                            field.dataset.backgroundColor = selectedBg.value;
                        });
                    }
                }
            });

            selectedAlign.addEventListener('change', () => {
                if (!isTextSelection()) {
                    return;
                }
                if (selectedAnnotation) {
                    selectedAnnotation.textAlign = selectedAlign.value;
                    applyAnnotationStyle(selectedAnnotation);
                    persistAnnotations();
                    return;
                }
                if (selectedOverlayField) {
                    applyOverlayStyle((textEl, field) => {
                        textEl.style.textAlign = selectedAlign.value;
                        field.style.textAlign = selectedAlign.value;
                        field.dataset.textAlign = selectedAlign.value;
                    });
                }
            });

            selectedOpacity.addEventListener('change', () => {
                if (!isTextSelection()) {
                    return;
                }
                if (selectedAnnotation) {
                    selectedAnnotation.opacity = parseFloat(selectedOpacity.value || '1') || 1;
                    applyAnnotationStyle(selectedAnnotation);
                    persistAnnotations();
                    return;
                }
                if (selectedOverlayField) {
                    const value = parseFloat(selectedOpacity.value || '1') || 1;
                    applyOverlayStyle((textEl, field) => {
                        textEl.style.opacity = String(value);
                        field.dataset.opacity = String(value);
                    });
                }
            });

            selectedDelete.addEventListener('click', () => {
                if (selectedAnnotation) {
                    const element = selectedAnnotation.element;
                    const idx = annotations.indexOf(selectedAnnotation);
                    if (idx >= 0) {
                        annotations.splice(idx, 1);
                    }
                    if (element && element.parentNode) {
                        element.parentNode.removeChild(element);
                    }
                    selectedAnnotation = null;
                    updateSelectionBar();
                    persistAnnotations();
                    updateAnnotationsList();
                    setStatus('Text deleted. Click Save to keep changes.', 'ok');
                    return;
                }
                if (selectedOverlayField) {
                    const field = selectedOverlayField;
                    const pageNumber = parseInt(field.dataset.pageNumber || '0', 10);
                    const key = field.dataset.wordIndex || `${pageNumber}-0`;
                    const textEl = getOverlayTextElement(field);
                    const originalWord = buildOriginalWordFromField(field, textEl ? textEl.textContent : '');
                    overlayEditedFields.set(key, {
                        page_number: pageNumber,
                        original_text: originalWord.text,
                        new_text: '',
                        original_bbox: [originalWord.left, originalWord.top, originalWord.left + originalWord.width, originalWord.top + originalWord.height],
                        bbox: [originalWord.left, originalWord.top, originalWord.left + originalWord.width, originalWord.top + originalWord.height],
                        font_xref: field.dataset.fontXref ? parseInt(field.dataset.fontXref, 10) : null,
                        font: originalWord.font,
                        font_size: originalWord.font_size,
                        color: field.dataset.textColor || '#000000'
                    });
                    field.remove();
                    clearOverlaySelection();
                    updateSelectionBar();
                    updateOverlaySaveButton();
                    persistOverlayEdits();
                }
            });

            insertX.addEventListener('click', () => {
                insertMode = insertMode === 'x' ? null : 'x';
                insertCheckbox.classList.remove('pill-active');
                insertX.classList.toggle('pill-active', insertMode === 'x');
                toolMode = 'select';
                updateModeButtons();
                setStatus(insertMode ? 'Click on the PDF to place an X.' : 'Insert mode cleared.', 'ok');
            });

            insertCheckbox.addEventListener('click', () => {
                insertMode = insertMode === 'checkbox' ? null : 'checkbox';
                insertX.classList.remove('pill-active');
                insertCheckbox.classList.toggle('pill-active', insertMode === 'checkbox');
                toolMode = 'select';
                updateModeButtons();
                setStatus(insertMode ? 'Click on the PDF to place a checkbox.' : 'Insert mode cleared.', 'ok');
            });

            const signatureCtx = signatureCanvas.getContext('2d');
            signatureCtx.lineWidth = 3;
            signatureCtx.lineCap = 'round';
            signatureCtx.lineJoin = 'round';
            signatureCtx.strokeStyle = '#111';
            signatureCtx.clearRect(0, 0, signatureCanvas.width, signatureCanvas.height);

            let drawing = false;
            let signatureDirty = false;
            let signatureMode = 'draw';
            const getSignaturePoint = (event) => {
                const rect = signatureCanvas.getBoundingClientRect();
                const scaleX = signatureCanvas.width / rect.width;
                const scaleY = signatureCanvas.height / rect.height;
                return {
                    x: (event.clientX - rect.left) * scaleX,
                    y: (event.clientY - rect.top) * scaleY,
                };
            };

            const startSignature = (event) => {
                if (signatureMode !== 'draw') {
                    return;
                }
                drawing = true;
                const point = getSignaturePoint(event);
                signatureCtx.beginPath();
                signatureCtx.moveTo(point.x, point.y);
                signatureDirty = true;
                if (signatureSave) {
                    signatureSave.disabled = false;
                }
            };

            const drawSignature = (event) => {
                if (signatureMode !== 'draw') {
                    return;
                }
                if (!drawing) {
                    return;
                }
                const point = getSignaturePoint(event);
                signatureCtx.lineTo(point.x, point.y);
                signatureCtx.stroke();
            };

            const endSignature = () => {
                drawing = false;
            };

            signatureCanvas.addEventListener('pointerdown', startSignature);
            signatureCanvas.addEventListener('pointermove', drawSignature);
            signatureCanvas.addEventListener('pointerup', endSignature);
            signatureCanvas.addEventListener('pointerleave', endSignature);

            function openSignatureModal() {
                signatureModal.classList.add('active');
                signatureModal.setAttribute('aria-hidden', 'false');
                if (signatureSave) {
                    signatureSave.disabled = !signatureDirty;
                }
                setSignatureMode(signatureMode || 'draw');
            }

            function closeSignatureModal() {
                signatureModal.classList.remove('active');
                signatureModal.setAttribute('aria-hidden', 'true');
            }

            signatureClear.addEventListener('click', () => {
                signatureCtx.clearRect(0, 0, signatureCanvas.width, signatureCanvas.height);
                signatureDirty = false;
                if (signatureText) {
                    signatureText.value = '';
                }
                if (signatureSave) {
                    signatureSave.disabled = true;
                }
            });

            signatureCancel.addEventListener('click', () => {
                closeSignatureModal();
            });

            if (signatureClose) {
                signatureClose.addEventListener('click', () => {
                    closeSignatureModal();
                });
            }

            if (signatureColor) {
                signatureColor.addEventListener('input', () => {
                    signatureCtx.strokeStyle = signatureColor.value;
                    const label = signatureColor.parentElement?.querySelector('span');
                    if (label) {
                        label.textContent = signatureColor.value;
                    }
                });
            }

            if (signatureWidth) {
                signatureWidth.addEventListener('input', () => {
                    const widthValue = parseInt(signatureWidth.value || '3', 10);
                    signatureCtx.lineWidth = widthValue;
                    if (signatureWidthLabel) {
                        signatureWidthLabel.textContent = String(widthValue);
                    }
                });
            }

            const ensureSignatureFontLoaded = (fontName) => {
                if (!fontName) return;
                const id = `signature-font-${fontName.replace(/\s+/g, '-')}`;
                if (document.getElementById(id)) return;
                const link = document.createElement('link');
                link.id = id;
                link.rel = 'stylesheet';
                link.href = `https://fonts.googleapis.com/css2?family=${encodeURIComponent(fontName)}:wght@400;700&display=swap`;
                document.head.appendChild(link);
            };

            const renderSignatureText = () => {
                if (signatureMode !== 'write') {
                    return;
                }
                const text = (signatureText?.value || '').trim();
                signatureCtx.clearRect(0, 0, signatureCanvas.width, signatureCanvas.height);
                if (!text) {
                    signatureDirty = false;
                    if (signatureSave) {
                        signatureSave.disabled = true;
                    }
                    return;
                }
                signatureDirty = true;
                if (signatureSave) {
                    signatureSave.disabled = false;
                }
                const fontName = signatureFont?.value || 'Great Vibes';
                ensureSignatureFontLoaded(fontName);
                const maxWidth = signatureCanvas.width * 0.8;
                let fontSize = 120;
                signatureCtx.textAlign = 'center';
                signatureCtx.textBaseline = 'middle';
                signatureCtx.fillStyle = signatureColor?.value || '#111';
                while (fontSize > 24) {
                    signatureCtx.font = `${fontSize}px "${fontName}"`;
                    if (signatureCtx.measureText(text).width <= maxWidth) {
                        break;
                    }
                    fontSize -= 4;
                }
                signatureCtx.fillText(text, signatureCanvas.width / 2, signatureCanvas.height / 2);
            };

            const setSignatureMode = (mode) => {
                signatureMode = mode;
                signatureTabs.forEach((tab) => {
                    tab.classList.toggle('active', tab.dataset.signatureTab === mode);
                });
                signaturePanels.forEach((panel) => {
                    panel.classList.toggle('active', panel.dataset.signaturePanel === mode);
                });
                if (mode === 'write') {
                    renderSignatureText();
                }
            };

            signatureTabs.forEach((tab) => {
                tab.addEventListener('click', () => {
                    setSignatureMode(tab.dataset.signatureTab || 'draw');
                });
            });

            if (signatureFont) {
                signatureFont.addEventListener('change', renderSignatureText);
            }
            if (signatureText) {
                signatureText.addEventListener('input', renderSignatureText);
            }

            signatureSave.addEventListener('click', () => {
                if (!signatureDirty) {
                    return;
                }
                signatureDataUrl = signatureCanvas.toDataURL('image/png');
                closeSignatureModal();
                toolMode = 'select';
                updateModeButtons();
                placeSignatureOnFirstPage();
            });

            // Shape Modal
            const shapeModal = document.getElementById('shape-modal');
            const shapeClose = document.getElementById('shape-close');
            const shapeApply = document.getElementById('shape-apply');
            const shapeTypeButtons = document.querySelectorAll('.shape-type-btn');
            const shapeStrokeColorInput = document.getElementById('shape-stroke-color');
            const shapeStrokeHexInput = document.getElementById('shape-stroke-hex');
            const shapeStrokeTransparentInput = document.getElementById('shape-stroke-transparent');
            const shapeStrokeWidthInput = document.getElementById('shape-stroke-width');
            const shapeStrokeWidthFill = document.getElementById('shape-stroke-width-fill');
            const shapeStrokeWidthLabel = document.getElementById('shape-stroke-width-label');
            const shapeFillColorInput = document.getElementById('shape-fill-color');
            const shapeFillHexInput = document.getElementById('shape-fill-hex');
            const shapeFillTransparentInput = document.getElementById('shape-fill-transparent');
            const shapeOpacityInput = document.getElementById('shape-opacity');
            const shapeOpacityFill = document.getElementById('shape-opacity-fill');
            const shapeOpacityLabel = document.getElementById('shape-opacity-label');

            function openShapeModal() {
                shapeModal.classList.add('active');
                shapeModal.setAttribute('aria-hidden', 'false');
            }

            function closeShapeModal() {
                shapeModal.classList.remove('active');
                shapeModal.setAttribute('aria-hidden', 'true');
            }

            // Color picker for shapes
            function openShapeColorPicker(annotation, shapeSvg, label) {
                // Remove any existing color picker
                const existing = document.getElementById('shape-color-picker-modal');
                if (existing) existing.remove();
                
                // Create modal
                const modal = document.createElement('div');
                modal.id = 'shape-color-picker-modal';
                modal.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; display: flex; align-items: center; justify-content: center;';
                
                const picker = document.createElement('div');
                picker.id = 'shape_modal_menu';
                picker.style.cssText = 'background: var(--bg); border: 1px solid rgba(255,255,255,0.2); border-radius: 12px; width: 380px; box-shadow: 0 8px 32px rgba(0,0,0,0.5); overflow: hidden; position: relative; cursor: move;';
                
                let currentTab = 'fill';
                let currentColor = annotation.fillTransparent ? '#000000' : annotation.fillColor;
                let currentOpacity = annotation.opacity * 100;
                
                const updatePreview = () => {
                    const color = currentTab === 'fill' ? 
                        (annotation.fillTransparent ? 'transparent' : annotation.fillColor) :
                        (annotation.strokeTransparent ? 'transparent' : annotation.strokeColor);
                    return color;
                };
                
                picker.innerHTML = `
                    <button id="close-modal-x" style="position: absolute; top: 12px; right: 12px; width: 28px; height: 28px; border-radius: 50%; background: rgba(255,255,255,0.1); color: var(--ink); border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: bold; z-index: 10; transition: background 0.2s;">&times;</button>
                    <div style="padding: 20px;">
                        <div style="margin-bottom: 16px;">
                            <div style="font-weight: 600; font-size: 14px; margin-bottom: 12px;">Text frame</div>
                            <div style="display: flex; gap: 8px;">
                                <button class="color-tab" data-tab="fill" style="flex: 1; padding: 10px; background: var(--accent); color: #053322; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px;">Fill</button>
                                <button class="color-tab" data-tab="border" style="flex: 1; padding: 10px; background: rgba(255,255,255,0.1); color: var(--ink); border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px;">Border line</button>
                            </div>
                        </div>
                        
                        <div style="display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap;">
                            ${['#000000', '#FF0000', '#0000FF', '#00A86B', '#FFD700', '#E0E0E0', '#FFFFFF', 'rainbow'].map(c => {
                                if (c === 'rainbow') {
                                    return `<button class="color-swatch" data-color="custom" style="width: 32px; height: 32px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.3); cursor: pointer; background: conic-gradient(red, yellow, lime, cyan, blue, magenta, red);"></button>`;
                                }
                                const isSelected = (currentTab === 'fill' ? annotation.fillColor : annotation.strokeColor) === c;
                                return `<button class="color-swatch" data-color="${c}" style="width: 32px; height: 32px; border-radius: 50%; background: ${c}; border: 3px solid ${isSelected ? 'var(--accent)' : (c === '#FFFFFF' ? '#000' : 'rgba(255,255,255,0.3)')}; cursor: pointer; box-shadow: ${isSelected ? '0 0 0 2px rgba(110, 231, 183, 0.3)' : 'none'};"></button>`;
                            }).join('')}
                        </div>
                        
                        <div style="margin-bottom: 16px;">
                            <div style="font-weight: 600; font-size: 14px; margin-bottom: 8px;">Opacity</div>
                            <input type="range" id="opacity-slider" min="0" max="100" value="${currentOpacity}" style="width: 100%; height: 4px; background: rgba(255,255,255,0.2); border-radius: 2px; outline: none; cursor: pointer;">
                        </div>
                        
                        <div style="position: relative; width: 100%; height: 200px; margin-bottom: 16px; background: linear-gradient(to bottom, transparent, black), linear-gradient(to right, white, transparent); border-radius: 8px; cursor: crosshair;" id="color-gradient">
                            <div id="color-picker-thumb" style="position: absolute; width: 16px; height: 16px; border: 3px solid white; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.3); pointer-events: none; transform: translate(-50%, -50%);"></div>
                        </div>
                        
                        <div style="position: relative; width: 100%; height: 24px; margin-bottom: 16px; background: linear-gradient(to right, #ff0000, #ffff00, #00ff00, #00ffff, #0000ff, #ff00ff, #ff0000); border-radius: 4px; cursor: pointer;" id="hue-slider">
                            <div id="hue-thumb" style="position: absolute; width: 4px; height: 100%; background: white; border: 1px solid rgba(0,0,0,0.3); border-radius: 2px; pointer-events: none; transform: translateX(-50%);"></div>
                        </div>
                        
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px;">
                            <input type="text" id="hex-input" value="${currentColor}" style="flex: 1; padding: 8px 12px; background: var(--panel); border: 1px solid rgba(255,255,255,0.2); border-radius: 6px; color: var(--ink); font-size: 14px; text-align: center; text-transform: uppercase;">
                            <div style="text-align: center; font-size: 12px; color: var(--muted);">HEX</div>
                        </div>
                        
                        <div style="display: flex; gap: 8px;">
                            <button id="color-cancel" style="flex: 1; padding: 10px; background: rgba(255,255,255,0.1); color: var(--ink); border: none; border-radius: 8px; cursor: pointer; font-weight: 600;">Cancel</button>
                            <button id="color-confirm" style="flex: 1; padding: 10px; background: #2c3e50; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600;">Confirm</button>
                        </div>
                    </div>
                `;
                
                modal.appendChild(picker);
                document.body.appendChild(modal);
                
                // Get elements
                const colorGradient = picker.querySelector('#color-gradient');
                const hueSlider = picker.querySelector('#hue-slider');
                const hueThumb = picker.querySelector('#hue-thumb');
                
                let currentHue = 0; // Store current hue value (0-360)
                
                // Helper function to convert HSV to RGB
                const hsvToRgb = (h, s, v) => {
                    let r, g, b;
                    const i = Math.floor(h * 6);
                    const f = h * 6 - i;
                    const p = v * (1 - s);
                    const q = v * (1 - f * s);
                    const t = v * (1 - (1 - f) * s);
                    switch (i % 6) {
                        case 0: r = v; g = t; b = p; break;
                        case 1: r = q; g = v; b = p; break;
                        case 2: r = p; g = v; b = t; break;
                        case 3: r = p; g = q; b = v; break;
                        case 4: r = t; g = p; b = v; break;
                        case 5: r = v; g = p; b = q; break;
                    }
                    return {
                        r: Math.round(r * 255),
                        g: Math.round(g * 255),
                        b: Math.round(b * 255)
                    };
                };
                
                // Helper function to convert RGB to hex
                const rgbToHex = (r, g, b) => {
                    return '#' + [r, g, b].map(x => {
                        const hex = x.toString(16);
                        return hex.length === 1 ? '0' + hex : hex;
                    }).join('');
                };
                
                // Helper function to update color gradient based on hue
                const updateColorGradient = (hue) => {
                    const rgb = hsvToRgb(hue / 360, 1, 1);
                    const hueColor = rgbToHex(rgb.r, rgb.g, rgb.b);
                    colorGradient.style.background = `
                        linear-gradient(to bottom, transparent, black),
                        linear-gradient(to right, white, ${hueColor})
                    `;
                };
                
                // Initialize gradient with current hue
                const hexToHue = (hex) => {
                    const r = parseInt(hex.slice(1, 3), 16) / 255;
                    const g = parseInt(hex.slice(3, 5), 16) / 255;
                    const b = parseInt(hex.slice(5, 7), 16) / 255;
                    const max = Math.max(r, g, b);
                    const min = Math.min(r, g, b);
                    const delta = max - min;
                    let h = 0;
                    if (delta !== 0) {
                        if (max === r) {
                            h = ((g - b) / delta + (g < b ? 6 : 0)) / 6;
                        } else if (max === g) {
                            h = ((b - r) / delta + 2) / 6;
                        } else {
                            h = ((r - g) / delta + 4) / 6;
                        }
                    }
                    return h * 360;
                };
                
                currentHue = hexToHue(currentColor);
                updateColorGradient(currentHue);
                hueThumb.style.left = (currentHue / 360 * 100) + '%';
                
                // Hue slider interaction
                const updateHueFromEvent = (e) => {
                    const rect = hueSlider.getBoundingClientRect();
                    const x = Math.max(0, Math.min(e.clientX - rect.left, rect.width));
                    const huePercent = x / rect.width;
                    currentHue = huePercent * 360;
                    hueThumb.style.left = (huePercent * 100) + '%';
                    updateColorGradient(currentHue);
                    
                    // Update current color based on hue
                    const rgb = hsvToRgb(currentHue / 360, 1, 1);
                    currentColor = rgbToHex(rgb.r, rgb.g, rgb.b);
                    picker.querySelector('#hex-input').value = currentColor;
                    highlightSelectedSwatch();
                    updateShapePreview();
                };
                
                let isHueDragging = false;
                hueSlider.addEventListener('mousedown', (e) => {
                    isHueDragging = true;
                    updateHueFromEvent(e);
                    e.preventDefault();
                    e.stopPropagation();
                });
                
                document.addEventListener('mousemove', (e) => {
                    if (isHueDragging) {
                        updateHueFromEvent(e);
                    }
                });
                
                document.addEventListener('mouseup', () => {
                    isHueDragging = false;
                });
                
                // Helper function to update shape preview
                const updateShapePreview = () => {
                    const shapes = shapeSvg.children;
                    for (let shape of shapes) {
                        if (currentTab === 'fill') {
                            shape.setAttribute('fill', currentColor);
                        } else {
                            shape.setAttribute('stroke', currentColor);
                        }
                        shape.setAttribute('opacity', String(currentOpacity / 100));
                    }
                };
                
                // Helper function to highlight selected swatch
                const highlightSelectedSwatch = () => {
                    picker.querySelectorAll('.color-swatch').forEach(sw => {
                        const swatchColor = sw.dataset.color;
                        if (swatchColor !== 'custom' && swatchColor === currentColor) {
                            sw.style.border = '3px solid var(--accent)';
                            sw.style.boxShadow = '0 0 0 2px rgba(110, 231, 183, 0.3)';
                        } else {
                            sw.style.border = swatchColor === '#FFFFFF' ? '3px solid #000' : '3px solid rgba(255,255,255,0.3)';
                            sw.style.boxShadow = 'none';
                        }
                    });
                };
                
                // Close button X
                picker.querySelector('#close-modal-x').addEventListener('click', () => {
                    modal.remove();
                });
                picker.querySelector('#close-modal-x').addEventListener('mouseenter', function() {
                    this.style.background = 'rgba(255,255,255,0.2)';
                });
                picker.querySelector('#close-modal-x').addEventListener('mouseleave', function() {
                    this.style.background = 'rgba(255,255,255,0.1)';
                });
                
                // Make picker draggable
                let isDragging = false;
                let dragOffsetX = 0;
                let dragOffsetY = 0;
                
                picker.addEventListener('mousedown', (e) => {
                    // Only drag if clicking on the picker itself or header area, not on interactive elements
                    if (e.target.tagName === 'INPUT' || e.target.tagName === 'BUTTON' || e.target.closest('button') || e.target.closest('input')) {
                        return;
                    }
                    isDragging = true;
                    const rect = picker.getBoundingClientRect();
                    dragOffsetX = e.clientX - rect.left;
                    dragOffsetY = e.clientY - rect.top;
                    picker.style.cursor = 'grabbing';
                    e.preventDefault();
                });
                
                document.addEventListener('mousemove', (e) => {
                    if (!isDragging) return;
                    const x = e.clientX - dragOffsetX;
                    const y = e.clientY - dragOffsetY;
                    picker.style.position = 'fixed';
                    picker.style.left = x + 'px';
                    picker.style.top = y + 'px';
                    picker.style.transform = 'none';
                });
                
                document.addEventListener('mouseup', () => {
                    if (isDragging) {
                        isDragging = false;
                        picker.style.cursor = 'move';
                    }
                });
                
                // Tab switching
                const tabs = picker.querySelectorAll('.color-tab');
                tabs.forEach(tab => {
                    tab.addEventListener('click', () => {
                        currentTab = tab.dataset.tab;
                        tabs.forEach(t => {
                            if (t.dataset.tab === currentTab) {
                                t.style.background = 'var(--accent)';
                                t.style.color = '#053322';
                            } else {
                                t.style.background = 'rgba(255,255,255,0.1)';
                                t.style.color = 'var(--ink)';
                            }
                        });
                        currentColor = currentTab === 'fill' ? annotation.fillColor : annotation.strokeColor;
                        picker.querySelector('#hex-input').value = currentColor;
                        highlightSelectedSwatch();
                    });
                });
                
                // Color swatches
                picker.querySelectorAll('.color-swatch').forEach(swatch => {
                    swatch.addEventListener('click', () => {
                        const color = swatch.dataset.color;
                        if (color !== 'custom') {
                            currentColor = color;
                            picker.querySelector('#hex-input').value = color;
                            highlightSelectedSwatch();
                            updateShapePreview();
                        }
                    });
                });
                
                // Hex input
                const hexInput = picker.querySelector('#hex-input');
                hexInput.addEventListener('input', (e) => {
                    if (/^#[0-9A-F]{6}$/i.test(e.target.value)) {
                        currentColor = e.target.value;
                        highlightSelectedSwatch();
                        updateShapePreview();
                    }
                });
                
                // Opacity slider
                const opacitySlider = picker.querySelector('#opacity-slider');
                opacitySlider.addEventListener('input', (e) => {
                    currentOpacity = parseInt(e.target.value);
                    updateShapePreview();
                });
                
                // Cancel
                picker.querySelector('#color-cancel').addEventListener('click', () => {
                    modal.remove();
                });
                
                // Confirm
                picker.querySelector('#color-confirm').addEventListener('click', () => {
                    if (currentTab === 'fill') {
                        annotation.fillColor = currentColor;
                        annotation.fillTransparent = false;
                    } else {
                        annotation.strokeColor = currentColor;
                        annotation.strokeTransparent = false;
                    }
                    annotation.opacity = currentOpacity / 100;
                    
                    // Update SVG
                    const shapes = shapeSvg.children;
                    for (let shape of shapes) {
                        if (currentTab === 'fill') {
                            shape.setAttribute('fill', currentColor);
                        } else {
                            shape.setAttribute('stroke', currentColor);
                        }
                        shape.setAttribute('opacity', String(annotation.opacity));
                    }
                    
                    persistAnnotations();
                    // Save updated annotation to database
                    saveAnnotationToDatabase(annotation);
                    setStatus('Shape color changed. Click Save to keep changes.', 'ok');
                    modal.remove();
                });
                
                // Close on background click
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) {
                        modal.remove();
                    }
                });
            }

            // Shape type selection
            shapeTypeButtons.forEach(btn => {
                btn.addEventListener('click', () => {
                    shapeTypeButtons.forEach(b => {
                        b.classList.remove('active');
                        b.style.border = '2px solid rgba(255,255,255,0.2)';
                        b.style.background = 'transparent';
                    });
                    btn.classList.add('active');
                    btn.style.border = '2px solid var(--accent)';
                    btn.style.background = 'rgba(110, 231, 183, 0.1)';
                    shapeType = btn.dataset.shape;
                });
            });

            // Stroke color sync
            shapeStrokeColorInput.addEventListener('input', () => {
                shapeStrokeHexInput.value = shapeStrokeColorInput.value;
                shapeStroke = shapeStrokeColorInput.value;
            });
            shapeStrokeHexInput.addEventListener('input', () => {
                if (/^#[0-9A-F]{6}$/i.test(shapeStrokeHexInput.value)) {
                    shapeStrokeColorInput.value = shapeStrokeHexInput.value;
                    shapeStroke = shapeStrokeHexInput.value;
                }
            });

            // Stroke transparent checkbox
            shapeStrokeTransparentInput.addEventListener('change', () => {
                shapeStrokeTransparentState = shapeStrokeTransparentInput.checked;
            });

            // Stroke width slider
            shapeStrokeWidthInput.addEventListener('input', () => {
                const value = parseInt(shapeStrokeWidthInput.value);
                const percentage = ((value - 1) / 19) * 100;
                shapeStrokeWidthFill.style.width = percentage + '%';
                shapeStrokeWidthLabel.textContent = Math.round(percentage) + '%';
                shapeStrokeWidth = value;
            });

            // Fill color sync
            shapeFillColorInput.addEventListener('input', () => {
                shapeFillHexInput.value = shapeFillColorInput.value;
                shapeFill = shapeFillColorInput.value;
            });
            shapeFillHexInput.addEventListener('input', () => {
                if (/^#[0-9A-F]{6}$/i.test(shapeFillHexInput.value)) {
                    shapeFillColorInput.value = shapeFillHexInput.value;
                    shapeFill = shapeFillHexInput.value;
                }
            });

            // Fill transparent checkbox
            shapeFillTransparentInput.addEventListener('change', () => {
                shapeFillTransparentState = shapeFillTransparentInput.checked;
            });

            // Opacity slider
            shapeOpacityInput.addEventListener('input', () => {
                const value = parseInt(shapeOpacityInput.value);
                shapeOpacityFill.style.width = value + '%';
                shapeOpacityLabel.textContent = value + '%';
                shapeOpacityValue = value / 100;
            });

            // Close modal
            shapeClose.addEventListener('click', closeShapeModal);

            // Apply and start drawing
            shapeApply.addEventListener('click', () => {
                closeShapeModal();
                toolMode = 'shape';
                insertMode = null;
                insertX.classList.remove('pill-active');
                insertCheckbox.classList.remove('pill-active');
                updateModeButtons();
                updateEditTextBanner();
                setStatus('Shape mode active. Drag to draw a shape.', 'ok');
            });

            // Organize Pages Modal
            organizePagesBtn.addEventListener('click', async () => {
                organizePagesModal.classList.add('active');
                await populateOrganizePagesModal();
            });

            organizeClose.addEventListener('click', () => {
                organizePagesModal.classList.remove('active');
            });

            organizeCancel.addEventListener('click', () => {
                organizePagesModal.classList.remove('active');
            });

            organizeApply.addEventListener('click', async () => {
                await applyPageReorder();
            });

            async function populateOrganizePagesModal() {
                organizePagesGrid.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 20px; color: #666;">Loading pages...</div>';
                
                try {
                    if (!pdfjsDocument) {
                        await loadPdf();
                    }
                    
                    organizePagesGrid.innerHTML = '';
                    organizePageOrder = [];
                    
                    for (let pageNum = 1; pageNum <= totalPages; pageNum++) {
                        const page = await pdfjsDocument.getPage(pageNum);
                        const viewport = page.getViewport({ scale: 0.5 });
                        
                        const pageItem = document.createElement('div');
                        pageItem.className = 'organize-page-item';
                        pageItem.draggable = true;
                        pageItem.dataset.pageNumber = pageNum;
                        
                        const pageNumber = document.createElement('div');
                        pageNumber.className = 'page-number';
                        pageNumber.textContent = pageNum;
                        
                        const canvas = document.createElement('canvas');
                        const context = canvas.getContext('2d');
                        canvas.height = viewport.height;
                        canvas.width = viewport.width;
                        
                        await page.render({ canvasContext: context, viewport }).promise;
                        
                        pageItem.appendChild(pageNumber);
                        pageItem.appendChild(canvas);
                        organizePagesGrid.appendChild(pageItem);
                        organizePageOrder.push(pageNum - 1); // Store 0-based index for backend
                        
                        // Click to select
                        pageItem.addEventListener('click', function() {
                            selectPageItem(this);
                        });
                        
                        // Drag and drop event handlers
                        setupPageItemDragEvents(pageItem);
                    }
                    
                    // Initialize toolbar button states
                    updateOrganizeToolbarButtons();
                } catch (error) {
                    console.error('Error populating organize pages modal:', error);
                    organizePagesGrid.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 20px; color: #ff6b6b;">Error loading pages</div>';
                }
            }

            function updateOrganizePageNumbers() {
                const items = organizePagesGrid.querySelectorAll('.organize-page-item');
                items.forEach((item, index) => {
                    const pageNumberEl = item.querySelector('.page-number');
                    if (pageNumberEl) {
                        pageNumberEl.textContent = index + 1;
                    }
                });
            }

            async function saveOverlayEditsIfNeeded() {
                if (overlayEditedFields.size === 0) {
                    return false;
                }

                setStatus('Saving overlay edits...', '');

                const edits = [];
                for (const [key, editData] of overlayEditedFields.entries()) {
                    console.log('Preparing edit:', key, editData);
                    
                    // Ensure color has # prefix
                    let color = editData.color || '#000000';
                    if (!color.startsWith('#')) {
                        color = '#' + color;
                    }
                    
                    edits.push({
                        page_number: editData.page_number,
                        block_num: editData.block_num,              // IMPORTANT: Include block_num for proper line handling
                        original_text: editData.original_text || '',
                        new_text: editData.new_text || '',
                        bbox: editData.bbox || [0, 0, 100, 100],
                        original_bbox: editData.original_bbox || editData.bbox || [0, 0, 100, 100],
                        origin_x: editData.origin_x || (editData.bbox ? editData.bbox[0] : 0),
                        origin_y: editData.origin_y || (editData.bbox ? editData.bbox[1] : 0),
                        font_xref: editData.font_xref || null,
                        font: editData.font || 'Helvetica',
                        font_size: editData.font_size || 12,
                        line_height: editData.line_height || null,  // Include line height
                        color: color,
                        rich_html: editData.new_text ? (editData.rich_html || null) : null
                    });
                }
                
                console.log('Sending edits to server:', edits);

                const saveResponse = await fetch('{{ route("documents.saveEdits", $document) }}', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ 
                        edits
                        // Note: skip_refresh is NOT set here - we want extraction to refresh after save
                    })
                });

                if (!saveResponse.ok) {
                    const errorText = await saveResponse.text();
                    throw new Error(errorText || `Overlay save failed (${saveResponse.status})`);
                }

                const saveResult = await saveResponse.json();

                if (!saveResult.success) {
                    throw new Error('Failed to save overlay edits: ' + (saveResult.message || 'Unknown error'));
                }

                // CRITICAL: Clear both edited and persisted edits after successful save
                // The text is now baked into the PDF, so we don't need overlay fields anymore
                overlayEditedFields.clear();
                overlayPersistedEdits.clear();
                
                // CRITICAL: Also clear sessionStorage to prevent reload of old edits
                try {
                    sessionStorage.removeItem(overlayEditsStorageKey);
                } catch (e) {
                    console.warn('Failed to clear sessionStorage:', e);
                }
                
                updateOverlaySaveButton();

                return true;
            }

            async function applyPageReorder() {
                try {
                    organizeApply.disabled = true;
                    organizeApply.textContent = 'Applying...';
                    
                    // Save any pending overlay edits before reordering
                    if (overlayEditedFields.size > 0) {
                        setStatus('Saving overlay edits before reordering...', '');
                        
                        // Build edits array from overlayEditedFields
                        const edits = [];
                        for (const [key, editData] of overlayEditedFields.entries()) {
                            // editData already contains the full edit structure
                            edits.push({
                                page_number: editData.page_number,
                                block_num: editData.block_num,              // Include block_num!
                                original_text: editData.original_text || '',
                                new_text: editData.new_text || '',
                                bbox: editData.bbox || [0, 0, 100, 100],
                                original_bbox: editData.original_bbox || editData.bbox || [0, 0, 100, 100],
                                origin_x: editData.origin_x || (editData.bbox ? editData.bbox[0] : 0),
                                origin_y: editData.origin_y || (editData.bbox ? editData.bbox[1] : 0),
                                font_xref: editData.font_xref || null,
                                font: editData.font || 'Helvetica',
                                font_size: editData.font_size || 12,
                                line_height: editData.line_height || null,  // Include line height
                                color: editData.color || '000000',
                                rich_html: editData.rich_html || null
                            });
                        }
                        
                        if (edits.length > 0) {
                            // Call saveEdits API
                            const saveResponse = await fetch('{{ route("documents.saveEdits", $document) }}', {
                                method: 'POST',
                                headers: {
                                    'X-CSRF-TOKEN': csrfToken,
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json'
                                },
                                body: JSON.stringify({ edits })
                            });
                            
                            const saveResult = await saveResponse.json();
                            
                            if (!saveResult.success) {
                                throw new Error('Failed to save overlay edits: ' + (saveResult.message || 'Unknown error'));
                            }
                            
                            // Clear the edited fields after successful save
                            overlayEditedFields.clear();
                            persistOverlayEdits();
                            
                            setStatus('Overlay edits saved. Now reordering pages...', '');
                        }
                    }
                    
                    const response = await fetch('{{ route("documents.reorderPages", $document) }}', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            page_order: organizePageOrder,
                            session_id: localStorage.getItem('pdf_session_id')
                        })
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        if (result.deleted_pages && result.deleted_pages.length > 0) {
                            console.log('Deleted pages:', result.deleted_pages);
                            console.log('Annotations cleared for these pages');
                        }
                        
                        organizePagesModal.classList.remove('active');
                        setStatus('Pages reordered successfully. Reloading...', 'ok');
                        
                        // Reload the page to show new order
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    } else {
                        throw new Error(result.message || 'Failed to reorder pages');
                    }
                } catch (error) {
                    console.error('Error reordering pages:', error);
                    setStatus('Error reordering pages: ' + error.message, 'err');
                } finally {
                    organizeApply.disabled = false;
                    organizeApply.textContent = 'Apply';
                }
            }

            function updateOrganizeToolbarButtons() {
                const hasSelection = selectedPageItem !== null;
                const selectedIndex = selectedPageItem ? Array.from(organizePagesGrid.children).indexOf(selectedPageItem) : -1;
                const isFirst = selectedIndex === 0;
                const isLast = selectedIndex === organizePagesGrid.children.length - 1;
                
                deletePageBtn.disabled = !hasSelection || organizePagesGrid.children.length <= 1;
                rotatePageBtn.disabled = !hasSelection;
                moveLeftBtn.disabled = !hasSelection || isFirst;
                moveRightBtn.disabled = !hasSelection || isLast;
                moveToBtn.disabled = !hasSelection;
                duplicatePageBtn.disabled = !hasSelection;
            }

            function selectPageItem(pageItem) {
                if (selectedPageItem) {
                    selectedPageItem.classList.remove('selected');
                }
                selectedPageItem = pageItem;
                if (pageItem) {
                    pageItem.classList.add('selected');
                }
                updateOrganizeToolbarButtons();
            }

            // Organize toolbar button handlers
            deletePageBtn.addEventListener('click', () => {
                if (!selectedPageItem || organizePagesGrid.children.length <= 1) return;
                
                if (confirm('Are you sure you want to delete this page?')) {
                    const index = Array.from(organizePagesGrid.children).indexOf(selectedPageItem);
                    organizePageOrder.splice(index, 1);
                    selectedPageItem.remove();
                    selectedPageItem = null;
                    updateOrganizePageNumbers();
                    updateOrganizeToolbarButtons();
                }
            });

            addPageBtn.addEventListener('click', async () => {
                try {
                    addPageBtn.disabled = true;
                    const selectedIndex = selectedPageItem
                        ? Array.from(organizePagesGrid.children).indexOf(selectedPageItem)
                        : null;

                    setStatus('Adding blank page...', '');

                    const response = await fetch(addBlankPageUrl, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            insert_after: selectedIndex,
                            size_reference: selectedIndex
                        })
                    });

                    const result = await response.json();

                    if (!result.success) {
                        throw new Error(result.message || 'Failed to add page');
                    }

                    pdfVersion = Date.now();
                    pdfjsDocument = null;
                    await populateOrganizePagesModal();

                    if (selectedIndex !== null) {
                        const newItem = organizePagesGrid.children[selectedIndex + 1];
                        if (newItem) {
                            selectPageItem(newItem);
                        }
                    }

                    setStatus('Blank page added.', 'ok');
                } catch (error) {
                    console.error('Error adding blank page:', error);
                    setStatus('Error adding page: ' + error.message, 'err');
                } finally {
                    addPageBtn.disabled = false;
                }
            });

            rotatePageBtn.addEventListener('click', async () => {
                if (!selectedPageItem) return;
                
                const canvas = selectedPageItem.querySelector('canvas');
                if (canvas) {
                    // Rotate the canvas 90 degrees
                    const tempCanvas = document.createElement('canvas');
                    const tempCtx = tempCanvas.getContext('2d');
                    tempCanvas.width = canvas.height;
                    tempCanvas.height = canvas.width;
                    
                    tempCtx.translate(tempCanvas.width / 2, tempCanvas.height / 2);
                    tempCtx.rotate(90 * Math.PI / 180);
                    tempCtx.drawImage(canvas, -canvas.width / 2, -canvas.height / 2);
                    
                    canvas.width = tempCanvas.width;
                    canvas.height = tempCanvas.height;
                    canvas.getContext('2d').drawImage(tempCanvas, 0, 0);
                }
            });

            moveLeftBtn.addEventListener('click', () => {
                if (!selectedPageItem) return;
                
                const index = Array.from(organizePagesGrid.children).indexOf(selectedPageItem);
                if (index > 0) {
                    const prevItem = organizePagesGrid.children[index - 1];
                    organizePagesGrid.insertBefore(selectedPageItem, prevItem);
                    
                    const movedPage = organizePageOrder.splice(index, 1)[0];
                    organizePageOrder.splice(index - 1, 0, movedPage);
                    
                    updateOrganizePageNumbers();
                    updateOrganizeToolbarButtons();
                }
            });

            moveRightBtn.addEventListener('click', () => {
                if (!selectedPageItem) return;
                
                const index = Array.from(organizePagesGrid.children).indexOf(selectedPageItem);
                if (index < organizePagesGrid.children.length - 1) {
                    const nextItem = organizePagesGrid.children[index + 1];
                    organizePagesGrid.insertBefore(nextItem, selectedPageItem);
                    
                    const movedPage = organizePageOrder.splice(index, 1)[0];
                    organizePageOrder.splice(index + 1, 0, movedPage);
                    
                    updateOrganizePageNumbers();
                    updateOrganizeToolbarButtons();
                }
            });

            moveToBtn.addEventListener('click', () => {
                if (!selectedPageItem) return;
                
                const totalPages = organizePagesGrid.children.length;
                const currentPos = Array.from(organizePagesGrid.children).indexOf(selectedPageItem) + 1;
                const newPos = prompt(`Move page to position (1-${totalPages}):`, currentPos);
                
                if (newPos === null) return;
                
                const targetIndex = parseInt(newPos) - 1;
                if (isNaN(targetIndex) || targetIndex < 0 || targetIndex >= totalPages) {
                    alert('Invalid position');
                    return;
                }
                
                const currentIndex = currentPos - 1;
                if (currentIndex === targetIndex) return;
                
                const targetItem = organizePagesGrid.children[targetIndex];
                if (currentIndex < targetIndex) {
                    organizePagesGrid.insertBefore(selectedPageItem, targetItem.nextSibling);
                } else {
                    organizePagesGrid.insertBefore(selectedPageItem, targetItem);
                }
                
                const movedPage = organizePageOrder.splice(currentIndex, 1)[0];
                organizePageOrder.splice(targetIndex, 0, movedPage);
                
                updateOrganizePageNumbers();
                updateOrganizeToolbarButtons();
            });

            duplicatePageBtn.addEventListener('click', () => {
                if (!selectedPageItem) return;
                
                const index = Array.from(organizePagesGrid.children).indexOf(selectedPageItem);
                const clone = selectedPageItem.cloneNode(true);
                
                // Re-attach event listeners to the clone
                clone.addEventListener('click', function() {
                    selectPageItem(this);
                });
                
                setupPageItemDragEvents(clone);
                
                organizePagesGrid.insertBefore(clone, selectedPageItem.nextSibling);
                organizePageOrder.splice(index + 1, 0, organizePageOrder[index]);
                
                updateOrganizePageNumbers();
                selectPageItem(clone);
            });

            function setupPageItemDragEvents(pageItem) {
                pageItem.addEventListener('dragstart', (e) => {
                    draggedPageItem = pageItem;
                    pageItem.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                });
                
                pageItem.addEventListener('dragend', () => {
                    pageItem.classList.remove('dragging');
                    draggedPageItem = null;
                });
                
                pageItem.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    if (draggedPageItem && draggedPageItem !== pageItem) {
                        pageItem.classList.add('drag-over');
                    }
                });
                
                pageItem.addEventListener('dragleave', () => {
                    pageItem.classList.remove('drag-over');
                });
                
                pageItem.addEventListener('drop', (e) => {
                    e.preventDefault();
                    pageItem.classList.remove('drag-over');
                    
                    if (!draggedPageItem || draggedPageItem === pageItem) return;
                    
                    const draggedIndex = Array.from(organizePagesGrid.children).indexOf(draggedPageItem);
                    const targetIndex = Array.from(organizePagesGrid.children).indexOf(pageItem);
                    
                    if (draggedIndex < targetIndex) {
                        pageItem.parentNode.insertBefore(draggedPageItem, pageItem.nextSibling);
                    } else {
                        pageItem.parentNode.insertBefore(draggedPageItem, pageItem);
                    }
                    
                    const movedPage = organizePageOrder.splice(draggedIndex, 1)[0];
                    organizePageOrder.splice(targetIndex, 0, movedPage);
                    
                    updateOrganizePageNumbers();
                });
            }

            const updateModeButtons = () => {
                modeText.classList.toggle('active', toolMode === 'text');
                modeEditText.classList.toggle('active', toolMode === 'edit-text');
                modeSign.classList.toggle('active', toolMode === 'sign');
                modeShape.classList.toggle('active', toolMode === 'shape');
                if (modeOverlay) {
                    modeOverlay.checked = overlayEditorActive;
                }
                if (modeOverlayToggle) {
                    modeOverlayToggle.classList.toggle('active', overlayEditorActive);
                }
                updateTextLayerVisibility();
            };

            modeText.addEventListener('click', () => {
                exitOverlayEditorForTool();
                toolMode = 'text';
                insertMode = null;
                insertX.classList.remove('pill-active');
                insertCheckbox.classList.remove('pill-active');
                closeTextEditPopup();
                updateModeButtons();
                updateEditTextBanner();
                setStatus('Add Text mode active. Click on the PDF to add new text.', 'ok');
            });

            modeEditText.addEventListener('click', () => {
                // Switch to extracted text tab
                const extractedTextTab = document.getElementById('extracted-text-tab');
                extractedTextTab.click();
            });

            // Close popup when clicking outside
            document.addEventListener('click', (e) => {
                const popup = document.querySelector('.text-edit-popup');
                if (popup && !popup.contains(e.target) && !e.target.classList.contains('pdf-text')) {
                    closeTextEditPopup();
                }
            });

            function updateEditTextBanner() {
                const banner = document.getElementById('edit-text-banner');
                const countEl = document.getElementById('modified-count');
                if (toolMode === 'edit-text') {
                    banner.classList.add('visible');
                    const modifiedCount = pdfTextItems.filter(item => item.modified).length;
                    countEl.textContent = modifiedCount + ' modified';
                } else {
                    banner.classList.remove('visible');
                }
            }

            let ocrViewActive = false;
            let ocrDataLoaded = false;

            async function loadOcrDocument() {
                const ocrLoading = document.getElementById('ocr-loading');
                const ocrDocument = document.getElementById('ocr-document');
                
                try {
                    const response = await fetch(extractionDataUrl);
                    const data = await response.json();
                    
                    if (!data.success) {
                        ocrLoading.innerHTML = `
                            <div style="color: #f44336;">
                                <h2>No OCR Data Available</h2>
                                <p>${data.message}</p>
                                <p>Please wait for OCR processing to complete and try again.</p>
                            </div>
                        `;
                        return;
                    }
                    
                    ocrLoading.style.display = 'none';
                    
                    let html = '';
                    data.extraction_data.forEach((page, pageIndex) => {
                        html += '<div class="ocr-page">';
                        html += '<div class="ocr-page-number">— Page ' + (page.page_number || pageIndex + 1) + ' —</div>';
                        
                        if (page.words && page.words.length > 0) {
                            // Group words by paragraph number (par_num) and block for better layout
                            const paragraphs = {};
                            page.words.forEach(word => {
                                // Use block_num and par_num for better grouping
                                const parKey = (word.block_num || 0) + '-' + (word.par_num || 0);
                                if (!paragraphs[parKey]) {
                                    paragraphs[parKey] = {
                                        words: [],
                                        block_num: word.block_num,
                                        par_num: word.par_num
                                    };
                                }
                                paragraphs[parKey].words.push(word);
                            });
                            
                            // Sort paragraphs by position (top to bottom, left to right)
                            const sortedParagraphs = Object.values(paragraphs).sort((a, b) => {
                                const aTop = Math.min(...a.words.map(w => w.top));
                                const bTop = Math.min(...b.words.map(w => w.top));
                                return aTop - bTop;
                            });
                            
                            // Render each paragraph
                            sortedParagraphs.forEach((par, parIndex) => {
                                // Sort words within paragraph by line, then by position
                                const sortedWords = par.words.sort((a, b) => {
                                    if (a.line_num !== b.line_num) return a.line_num - b.line_num;
                                    return a.left - b.left;
                                });
                                
                                // Group by lines within paragraph
                                const lines = {};
                                sortedWords.forEach(word => {
                                    const lineKey = word.line_num || 0;
                                    if (!lines[lineKey]) lines[lineKey] = [];
                                    lines[lineKey].push(word);
                                });
                                
                                // Build paragraph text with line breaks
                                const paragraphLines = Object.values(lines).map(lineWords => {
                                    return lineWords.map(w => w.text).join(' ');
                                });
                                const paragraphText = paragraphLines.join('<br>');
                                
                                html += '<div class="ocr-paragraph" contenteditable="true" ' +
                                    'data-page="' + pageIndex + '" ' +
                                    'data-block="' + (par.block_num || 0) + '" ' +
                                    'data-par="' + (par.par_num || 0) + '">' +
                                    paragraphText +
                                    '</div>';
                            });
                        } else {
                            html += '<p style="color: #999; text-align: center; font-style: italic;">No text detected on this page</p>';
                        }
                        
                        html += '</div>';
                    });
                    
                    ocrDocument.innerHTML = html;
                    ocrDataLoaded = true;
                    
                } catch (error) {
                    ocrLoading.innerHTML = '<div style="color: #f44336; text-align: center;"><h2>Error Loading Data</h2><p>' + error.message + '</p></div>';
                }
            }

            modeSign.addEventListener('click', () => {
                toolMode = 'sign';
                insertMode = null;
                insertX.classList.remove('pill-active');
                insertCheckbox.classList.remove('pill-active');
                updateModeButtons();
                updateEditTextBanner();
                openSignatureModal();
            });

            modeShape.addEventListener('click', () => {
                // Open shape settings modal
                openShapeModal();
            });

            const updateOverlayShowOriginalToggle = () => {
                // No longer needed
                return;
            };

            const exitOverlayEditorForTool = () => {
                if (!overlayEditorActive && !viewer.classList.contains('overlay-view-mode')) {
                    return;
                }
                cleanupOverlayPdf();  // Free memory from overlay PDF
                overlayEditorActive = false;
                overlayLoadToken++;
                persistOverlayEdits();
                if (saveOverlayBtn) {
                    saveOverlayBtn.style.display = 'none';
                }
                document.querySelectorAll('.overlay-field [contenteditable]').forEach(el => {
                    el.contentEditable = false;
                });
                if (modeOverlay) {
                    modeOverlay.checked = false;
                }
                if (basePdfUrl === cleanPdfUrl && overlayExtractionData) {
                    viewer.classList.add('overlay-view-mode');
                    viewer.classList.remove('overlay-hidden');
                    renderPdfWithOverlay(true);
                } else {
                    viewer.classList.remove('overlay-view-mode');
                    viewer.classList.add('overlay-hidden');
                    basePdfUrl = pdfUrl;
                    rerenderPdf();
                }
            };

            if (viewOriginalPdfBtn) {
                viewOriginalPdfBtn.addEventListener('click', () => {
                    // Open the original PDF in a new tab
                    const originalUrl = `${pdfUrl}?v=${Date.now()}`;
                    window.open(originalUrl, '_blank');
                });
            }

            const fontMatchBtn = document.getElementById('font-match-btn');
            if (fontMatchBtn) {
                fontMatchBtn.addEventListener('click', async () => {
                    fontMatchBtn.disabled = true;
                    fontMatchBtn.textContent = '⏳ Loading Fonts...';
                    setStatus('Analyzing PDF fonts...', '');
                    
                    try {
                        // Call the font matching endpoint
                        const response = await fetch('{{ route("documents.matchFonts", $document) }}', {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': csrfToken,
                                'Accept': 'application/json',
                                'Content-Type': 'application/json'
                            }
                        });
                        
                        const result = await response.json();
                        
                        if (result.success) {
                            let message = `✓ Loaded ${result.loaded_fonts}/${result.total_fonts} fonts successfully!`;
                            
                            // Show details of failed fonts if any
                            if (result.font_results) {
                                const failed = Object.entries(result.font_results)
                                    .filter(([name, info]) => info.status === 'failed')
                                    .map(([name]) => name);
                                
                                if (failed.length > 0) {
                                    message += `\nFailed: ${failed.join(', ')}`;
                                    console.log('Failed fonts details:', 
                                        Object.entries(result.font_results)
                                            .filter(([name, info]) => info.status === 'failed'));
                                }
                            }
                            
                            setStatus(message, result.loaded_fonts === result.total_fonts ? 'ok' : 'warn');
                            
                            // Inject the font CSS into the page
                            if (result.css_url) {
                                const link = document.createElement('link');
                                link.rel = 'stylesheet';
                                link.href = result.css_url;
                                document.head.appendChild(link);
                            }
                        } else {
                            let errorMsg = 'Font matching failed: ' + (result.message || 'Unknown error');
                            if (result.output) {
                                console.error('Font matching output:', result.output);
                                errorMsg += ' (Check console for details)';
                            }
                            setStatus(errorMsg, 'err');
                        }
                    } catch (error) {
                        console.error('Font matching error:', error);
                        setStatus('Error matching fonts: ' + error.message, 'err');
                    } finally {
                        fontMatchBtn.disabled = false;
                        fontMatchBtn.innerHTML = '🅐 Font Match';
                    }
                });
            }

            if (overlayUndoBtn) {
                overlayUndoBtn.addEventListener('click', overlayUndo);
            }

            if (overlayRedoBtn) {
                overlayRedoBtn.addEventListener('click', overlayRedo);
            }

            // Keyboard shortcuts for undo/redo
            // Copy/paste functionality
            let copiedAnnotation = null;
            
            document.addEventListener('keydown', (e) => {
                // Handle copy/paste for all annotations
                if (e.ctrlKey || e.metaKey) {
                    if (e.key === 'c' && selectedAnnotation) {
                        // Copy selected annotation
                        e.preventDefault();
                        copiedAnnotation = JSON.parse(JSON.stringify(selectedAnnotation));
                        setStatus('Annotation copied', 'ok');
                        return;
                    } else if (e.key === 'v' && copiedAnnotation) {
                        // Paste copied annotation
                        e.preventDefault();
                        
                        // Create new annotation with offset position
                        const newAnnotation = JSON.parse(JSON.stringify(copiedAnnotation));
                        delete newAnnotation.id; // Remove ID so it gets a new one
                        
                        // Offset position so it's visible (use a fixed offset in PDF coordinates)
                        newAnnotation.pdfX += 10;
                        newAnnotation.pdfY += 10;
                        
                        // Add to annotations array
                        annotations.push(newAnnotation);
                        persistAnnotations();
                        
                        // Get the wrapper and render the new annotation
                        const wrapper = viewer.querySelector(`[data-page-index="${newAnnotation.pageIndex}"]`);
                        if (wrapper) {
                            const overlay = wrapper.querySelector('.overlay');
                            const canvas = wrapper.querySelector('canvas');
                            if (overlay && canvas) {
                                const pageInfo = {
                                    scale: currentScale,
                                    canvasHeight: canvas.height,
                                };
                                addAnnotationElement(wrapper, newAnnotation, pageInfo);
                            }
                        }
                        
                        // Select the new annotation
                        setSelection(newAnnotation);
                        updateAnnotationsList();
                        setStatus('Annotation pasted', 'ok');
                        return;
                    }
                }
                
                // Handle undo/redo only in overlay mode
                if (!overlayEditorActive) return;
                
                if (e.ctrlKey || e.metaKey) {
                    if (e.key === 'z' && !e.shiftKey) {
                        e.preventDefault();
                        overlayUndo();
                    } else if (e.key === 'y' || (e.key === 'z' && e.shiftKey)) {
                        e.preventDefault();
                        overlayRedo();
                    }
                }
            });

            const populateFontDropdown = () => {
                if (!overlayExtractionData) return;
                
                const fontSelect = document.getElementById('selected-font');
                if (!fontSelect) return;
                
                // Collect unique fonts from extraction data
                const fontsSet = new Set();
                overlayExtractionData.forEach(pageData => {
                    if (pageData.blocks) {
                        pageData.blocks.forEach(block => {
                            if (block.font) {
                                // Clean font name
                                let cleaned = block.font;
                                if (cleaned.includes('+')) {
                                    const parts = cleaned.split('+', 2);
                                    if (parts[0].length === 6) {
                                        cleaned = parts[1];
                                    }
                                }
                                if (/^[A-Z]{6}[A-Z]/.test(cleaned)) {
                                    cleaned = cleaned.substring(6);
                                }
                                // Get base family name
                                const family = cleaned.split(/[-_]/)[0] || cleaned;
                                if (family) {
                                    fontsSet.add(family);
                                }
                            }
                        });
                    }
                    if (pageData.words) {
                        pageData.words.forEach(word => {
                            if (word.font) {
                                let cleaned = word.font;
                                if (cleaned.includes('+')) {
                                    const parts = cleaned.split('+', 2);
                                    if (parts[0].length === 6) {
                                        cleaned = parts[1];
                                    }
                                }
                                if (/^[A-Z]{6}[A-Z]/.test(cleaned)) {
                                    cleaned = cleaned.substring(6);
                                }
                                const family = cleaned.split(/[-_]/)[0] || cleaned;
                                if (family) {
                                    fontsSet.add(family);
                                }
                            }
                        });
                    }
                });
                
                // Clear existing options (keep first 3 defaults)
                const defaultOptions = Array.from(fontSelect.options).slice(0, 3);
                fontSelect.innerHTML = '';
                defaultOptions.forEach(opt => fontSelect.appendChild(opt));
                
                // Add separator if we have PDF fonts
                if (fontsSet.size > 0) {
                    const separator = document.createElement('option');
                    separator.disabled = true;
                    separator.textContent = '───── PDF Fonts ─────';
                    fontSelect.appendChild(separator);
                }
                
                // Add fonts from PDF (sorted)
                const sortedFonts = Array.from(fontsSet).sort();
                sortedFonts.forEach(font => {
                    const option = document.createElement('option');
                    option.value = font;
                    option.textContent = font;
                    fontSelect.appendChild(option);
                });
                
                console.log(`Added ${fontsSet.size} fonts from PDF to dropdown`);
            };

            modeOverlay?.addEventListener('change', async () => {
                if (!modeOverlay.checked) {
                    cleanupOverlayPdf();  // Free memory from overlay PDF
                    overlayEditorActive = false;
                    overlayLoadToken++;
                    persistOverlayEdits();
                    if (saveOverlayBtn) {
                        saveOverlayBtn.style.display = 'none';
                    }
                    viewer.classList.add('overlay-view-mode');
                    viewer.classList.remove('overlay-hidden');
                    document.querySelectorAll('.overlay-field [contenteditable]').forEach(el => {
                        el.contentEditable = false;
                    });
                    updateOverlayShowOriginalToggle();
                    toolMode = 'select';
                    updateModeButtons();
                    setStatus('Overlay editor closed. Text preserved.', 'ok');
                    if (basePdfUrl === cleanPdfUrl && overlayExtractionData) {
                        renderPdfWithOverlay(true);
                    } else {
                        viewer.classList.remove('overlay-view-mode');
                        rerenderPdf();
                    }
                    return;
                }

                // Enter overlay mode (always with block mode enabled)
                
                overlayEditorActive = true;
                basePdfUrl = cleanPdfUrl;
                pdfVersion = Date.now();
                const loadToken = ++overlayLoadToken;

                // Remove view mode and enable editing
                viewer.classList.remove('overlay-view-mode');
                viewer.classList.remove('overlay-hidden');

                // Re-enable contentEditable on all text fields
                document.querySelectorAll('.overlay-field [contenteditable]').forEach(el => {
                    el.contentEditable = true;
                });

                updateOverlayShowOriginalToggle();
                if (saveOverlayBtn) {
                    saveOverlayBtn.style.display = 'block';
                }
                toolMode = 'overlay';
                updateModeButtons();
                setStatus('Loading overlay editor...', 'loading');

                try {
                    viewer.classList.remove('overlay-hidden');
                    if (overlayRendered) {
                        setStatus('Overlay editor active. Edit text positions and content.', 'ok');
                        return;
                    }
                    // First, ensure clean PDF is created by calling the overlay editor endpoint
                    const prepareResponse = await fetch('{{ route("documents.prepareOverlay", $document) }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } });
                    if (!prepareResponse.ok) {
                        throw new Error('Failed to prepare clean PDF');
                    }
                    if (!overlayEditorActive || loadToken !== overlayLoadToken) {
                        return;
                    }

                    // Load extraction data
                    const response = await fetch('{{ route("documents.getFitzExtractionData", $document) }}');
                    const data = await response.json();

                    if (!data.success) {
                        throw new Error(data.message || 'Failed to load extraction data');
                    }
                    if (!overlayEditorActive || loadToken !== overlayLoadToken) {
                        return;
                    }

                    overlayExtractionData = data.extraction_data;
                    loadOverlayEdits();

                    // Populate font dropdown with fonts from PDF
                    populateFontDropdown();

                    // Load clean PDF
                    await renderPdfWithOverlay();
                    overlayRendered = true;
                    if (!overlayEditorActive || loadToken !== overlayLoadToken) {
                        return;
                    }
                    
                    setStatus('Overlay editor active. Edit text positions and content.', 'ok');
                } catch (error) {
                    console.error('Error loading overlay editor:', error);
                    setStatus('Error loading overlay editor: ' + error.message, 'err');
                    cleanupOverlayPdf();  // Free memory on error
                    overlayEditorActive = false;
                    overlayLoadToken++;
                    if (saveOverlayBtn) {
                        saveOverlayBtn.style.display = 'none';
                    }
                    if (modeOverlay) {
                        modeOverlay.checked = false;
                    }
                    viewer.classList.add('overlay-hidden');
                    basePdfUrl = pdfUrl;
                    rerenderPdf();
                }
            });

            async function renderPdfWithOverlay(force = false) {
                if (!overlayEditorActive && !force) {
                    return;
                }
                // Destroy old overlay PDF document to free memory
                if (overlayPdfDoc) {
                    overlayPdfDoc.destroy();
                    overlayPdfDoc = null;
                }
                // Always load clean PDF (with text removed)
                const loadingTask = pdfjsLib.getDocument(`${cleanPdfUrl}?v=${Date.now()}`);
                const pdf = await loadingTask.promise;
                overlayPdfDoc = pdf;  // Track for cleanup
                
                viewer.innerHTML = '';
                totalPages = pdf.numPages;
                updatePageControls();
                renderedPages = 0;
                
                // Render all pages with overlay
                for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++) {
                    await renderPageWithOverlay(pdf, pageNumber);
                }
            }
            
            async function renderPageWithOverlay(pdf, pageNumber) {
                const page = await pdf.getPage(pageNumber);
                const viewport = page.getViewport({ scale: currentScale });
                const pageMeta = { pageNumber, pageWidth: page.view[2], pageHeight: page.view[3] };
                
                const wrapper = document.createElement('div');
                wrapper.className = 'page-wrapper overlay-page';
                wrapper.dataset.pageNumber = pageNumber;
                wrapper.style.position = 'relative';
                wrapper.style.margin = '0 auto';
                wrapper.style.width = viewport.width + 'px';
                wrapper.style.height = viewport.height + 'px';
                
                const canvas = document.createElement('canvas');
                const context = canvas.getContext('2d');
                canvas.height = viewport.height;
                canvas.width = viewport.width;
                canvas.style.display = 'block';
                canvas.style.width = '100%';
                canvas.style.height = '100%';
                
                // Render PDF page
                await page.render({ canvasContext: context, viewport }).promise;
                
                // Create overlay for interactive text fields
                const overlay = document.createElement('div');
                overlay.className = 'pdf-overlay overlay';
                overlay.style.position = 'absolute';
                overlay.style.left = '0';
                overlay.style.top = '0';
                overlay.style.width = '100%';
                overlay.style.height = '100%';
                overlay.style.pointerEvents = 'auto';
                overlay.addEventListener('click', (event) => {
                    if (event.target === overlay) {
                        clearOverlaySelection();
                        updateSelectionBar();
                    }
                });
                overlay.addEventListener('click', (event) => {
                    if (event.target !== overlay) {
                        return;
                    }
                    if (toolMode !== 'text') {
                        return;
                    }
                    const rect = overlay.getBoundingClientRect();
                    const x = event.clientX - rect.left;
                    const y = event.clientY - rect.top;
                    const fontSizePx = Math.max(8, Math.min(48, parseInt(defaultTextSize, 10)));
                    const fontFamily = defaultTextFont;

                    const editor = document.createElement('input');
                    editor.type = 'text';
                    editor.className = 'text-editor';
                    editor.style.left = x + 'px';
                    editor.style.top = y + 'px';
                    editor.style.fontSize = fontSizePx + 'px';
                    editor.style.fontFamily = fontMap[fontFamily]?.css || 'inherit';
                    editor.placeholder = 'Type text here...';

                    activeEditor = editor;

                    let finished = false;
                    const finishEditing = () => {
                        if (finished) {
                            return;
                        }
                        finished = true;
                        const text = editor.value.trim();
                        if (text) {
                            const annotation = {
                                text,
                                pageIndex: pageNumber - 1,
                                pdfX: x / currentScale,
                                pdfY: (canvas.height - y) / currentScale,
                                fontSize: fontSizePx / currentScale,
                                fontFamily,
                                type: 'text',
                                textColor: '#111111',
                                backgroundColor: 'transparent',
                                fontWeight: 'normal',
                                fontStyle: 'normal',
                                underline: false,
                                textAlign: 'left',
                                opacity: 1,
                            };

                            normalizeTextAnnotation(annotation);
                            annotations.push(annotation);
                            persistAnnotations();
                            updateAnnotationsList();
                            addAnnotationElement(wrapper, annotation, pageInfo);
                            setSelection(annotation);
                            setStatus('Text added. Click Save to keep changes.', 'ok');
                        }
                        removeActiveEditor();
                    };

                    editor.addEventListener('blur', finishEditing);
                    editor.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') {
                            finishEditing();
                        } else if (e.key === 'Escape') {
                            removeActiveEditor();
                        }
                    });

                    overlay.appendChild(editor);
                    editor.focus();
                });
                
                // Setup shape drawing for this overlay (same as in renderPdf)
                let drawingShape = null;
                overlay.addEventListener('pointerdown', (event) => {
                    if (toolMode !== 'shape') {
                        return;
                    }
                    if (event.target !== overlay) {
                        return;
                    }
                    const rect = overlay.getBoundingClientRect();
                    const startX = event.clientX - rect.left;
                    const startY = event.clientY - rect.top;

                    const shapeWrapper = document.createElement('div');
                    shapeWrapper.className = 'annotation';
                    shapeWrapper.style.position = 'absolute';
                    shapeWrapper.style.left = startX + 'px';
                    shapeWrapper.style.top = startY + 'px';
                    shapeWrapper.style.width = '1px';
                    shapeWrapper.style.height = '1px';
                    shapeWrapper.style.padding = '0';
                    shapeWrapper.style.border = 'none';
                    shapeWrapper.style.background = 'transparent';
                    shapeWrapper.style.cursor = 'move';

                    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                    svg.setAttribute('width', '100%');
                    svg.setAttribute('height', '100%');
                    svg.setAttribute('viewBox', '0 0 100 100');
                    svg.setAttribute('preserveAspectRatio', 'none');
                    svg.style.display = 'block';

                    const shapeStrokeWidth = parseFloat(document.getElementById('shape-stroke-width')?.value) || 2;
                    const shapeOpacityValue = (parseFloat(document.getElementById('shape-opacity')?.value) || 100) / 100;
                    const strokeTransparent = document.getElementById('shape-stroke-transparent')?.checked || false;
                    const fillTransparent = document.getElementById('shape-fill-transparent')?.checked || false;
                    const strokeColor = strokeTransparent ? 'transparent' : (document.getElementById('shape-stroke-color-display')?.dataset.color || shapeStroke);
                    const fillColor = fillTransparent ? 'transparent' : (document.getElementById('shape-fill-color-display')?.dataset.color || shapeFill);

                    if (shapeType === 'circle' || shapeType === 'ellipse') {
                        const ellipse = document.createElementNS('http://www.w3.org/2000/svg', 'ellipse');
                        ellipse.setAttribute('cx', '50');
                        ellipse.setAttribute('cy', '50');
                        ellipse.setAttribute('rx', '48');
                        ellipse.setAttribute('ry', '48');
                        ellipse.setAttribute('fill', fillColor);
                        ellipse.setAttribute('stroke', strokeColor);
                        ellipse.setAttribute('stroke-width', String(shapeStrokeWidth));
                        ellipse.setAttribute('opacity', String(shapeOpacityValue));
                        svg.appendChild(ellipse);
                    } else if (shapeType === 'triangle') {
                        const triangle = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
                        triangle.setAttribute('points', '50,5 95,95 5,95');
                        triangle.setAttribute('fill', fillColor);
                        triangle.setAttribute('stroke', strokeColor);
                        triangle.setAttribute('stroke-width', String(shapeStrokeWidth));
                        triangle.setAttribute('stroke-linejoin', 'round');
                        triangle.setAttribute('opacity', String(shapeOpacityValue));
                        svg.appendChild(triangle);
                    } else if (shapeType === 'x') {
                        const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                        const line1 = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                        line1.setAttribute('x1', '15');
                        line1.setAttribute('y1', '15');
                        line1.setAttribute('x2', '85');
                        line1.setAttribute('y2', '85');
                        line1.setAttribute('stroke', strokeColor);
                        line1.setAttribute('stroke-width', String(shapeStrokeWidth));
                        line1.setAttribute('stroke-linecap', 'round');
                        line1.setAttribute('opacity', String(shapeOpacityValue));
                        const line2 = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                        line2.setAttribute('x1', '85');
                        line2.setAttribute('y1', '15');
                        line2.setAttribute('x2', '15');
                        line2.setAttribute('y2', '85');
                        line2.setAttribute('stroke', strokeColor);
                        line2.setAttribute('stroke-width', String(shapeStrokeWidth));
                        line2.setAttribute('stroke-linecap', 'round');
                        line2.setAttribute('opacity', String(shapeOpacityValue));
                        g.appendChild(line1);
                        g.appendChild(line2);
                        svg.appendChild(g);
                    } else if (shapeType === 'checkmark') {
                        const polyline = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
                        polyline.setAttribute('points', '15 50, 40 75, 85 15');
                        polyline.setAttribute('fill', 'none');
                        polyline.setAttribute('stroke', strokeColor);
                        polyline.setAttribute('stroke-width', String(shapeStrokeWidth));
                        polyline.setAttribute('stroke-linecap', 'round');
                        polyline.setAttribute('stroke-linejoin', 'round');
                        polyline.setAttribute('opacity', String(shapeOpacityValue));
                        svg.appendChild(polyline);
                    } else if (shapeType === 'star') {
                        const star = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
                        star.setAttribute('points', '50,5 61,38 95,38 68,58 79,91 50,71 21,91 32,58 5,38 39,38');
                        star.setAttribute('fill', fillColor);
                        star.setAttribute('stroke', strokeColor);
                        star.setAttribute('stroke-width', String(shapeStrokeWidth));
                        star.setAttribute('stroke-linejoin', 'round');
                        star.setAttribute('opacity', String(shapeOpacityValue));
                        svg.appendChild(star);
                    } else if (shapeType === 'polygon') {
                        const polygon = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
                        polygon.setAttribute('points', '50,5 90,27 90,73 50,95 10,73 10,27');
                        polygon.setAttribute('fill', fillColor);
                        polygon.setAttribute('stroke', strokeColor);
                        polygon.setAttribute('stroke-width', String(shapeStrokeWidth));
                        polygon.setAttribute('stroke-linejoin', 'round');
                        polygon.setAttribute('opacity', String(shapeOpacityValue));
                        svg.appendChild(polygon);
                    } else if (shapeType === 'arrow') {
                        const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                        const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                        line.setAttribute('x1', '10');
                        line.setAttribute('y1', '50');
                        line.setAttribute('x2', '80');
                        line.setAttribute('y2', '50');
                        line.setAttribute('stroke', strokeColor);
                        line.setAttribute('stroke-width', String(shapeStrokeWidth));
                        line.setAttribute('stroke-linecap', 'round');
                        line.setAttribute('opacity', String(shapeOpacityValue));
                        const arrowHead = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
                        arrowHead.setAttribute('points', '65,35 80,50 65,65');
                        arrowHead.setAttribute('fill', 'none');
                        arrowHead.setAttribute('stroke', strokeColor);
                        arrowHead.setAttribute('stroke-width', String(shapeStrokeWidth));
                        arrowHead.setAttribute('stroke-linecap', 'round');
                        arrowHead.setAttribute('stroke-linejoin', 'round');
                        arrowHead.setAttribute('opacity', String(shapeOpacityValue));
                        g.appendChild(line);
                        g.appendChild(arrowHead);
                        svg.appendChild(g);
                    } else {
                        const rectShape = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                        rectShape.setAttribute('x', '5');
                        rectShape.setAttribute('y', '5');
                        rectShape.setAttribute('width', '90');
                        rectShape.setAttribute('height', '90');
                        rectShape.setAttribute('fill', fillColor);
                        rectShape.setAttribute('stroke', strokeColor);
                        rectShape.setAttribute('stroke-width', String(shapeStrokeWidth));
                        rectShape.setAttribute('opacity', String(shapeOpacityValue));
                        svg.appendChild(rectShape);
                    }

                    shapeWrapper.appendChild(svg);
                    overlay.appendChild(shapeWrapper);

                    const pageInfo = {
                        scale: currentScale,
                        canvasHeight: canvas.height,
                    };

                    drawingShape = {
                        startX,
                        startY,
                        element: shapeWrapper,
                        pageInfo,
                        svg
                    };
                });

                overlay.addEventListener('pointermove', (event) => {
                    if (!drawingShape) {
                        return;
                    }
                    const rect = overlay.getBoundingClientRect();
                    const currentX = event.clientX - rect.left;
                    const currentY = event.clientY - rect.top;
                    const width = Math.abs(currentX - drawingShape.startX);
                    const height = Math.abs(currentY - drawingShape.startY);
                    const left = Math.min(currentX, drawingShape.startX);
                    const top = Math.min(currentY, drawingShape.startY);

                    drawingShape.element.style.left = left + 'px';
                    drawingShape.element.style.top = top + 'px';
                    drawingShape.element.style.width = width + 'px';
                    drawingShape.element.style.height = height + 'px';
                });

                overlay.addEventListener('pointerup', () => {
                    if (!drawingShape) {
                        return;
                    }
                    const width = parseInt(drawingShape.element.style.width);
                    const height = parseInt(drawingShape.element.style.height);
                    if (width < 5 || height < 5) {
                        drawingShape.element.remove();
                        drawingShape = null;
                        return;
                    }

                    const left = parseInt(drawingShape.element.style.left);
                    const top = parseInt(drawingShape.element.style.top);

                    const pdfX = left / currentScale;
                    const pdfY = (canvas.height - top) / currentScale - (height / currentScale);
                    const pdfWidth = width / currentScale;
                    const pdfHeight = height / currentScale;

                    const shapeStrokeWidth = parseFloat(document.getElementById('shape-stroke-width')?.value) || 2;
                    const shapeOpacityValue = (parseFloat(document.getElementById('shape-opacity')?.value) || 100) / 100;
                    const strokeTransparent = document.getElementById('shape-stroke-transparent')?.checked || false;
                    const fillTransparent = document.getElementById('shape-fill-transparent')?.checked || false;
                    const strokeColor = strokeTransparent ? 'transparent' : (document.getElementById('shape-stroke-color-display')?.dataset.color || shapeStroke);
                    const fillColor = fillTransparent ? 'transparent' : (document.getElementById('shape-fill-color-display')?.dataset.color || shapeFill);

                    const annotation = {
                        type: 'shape',
                        shapeType: shapeType,
                        pageIndex: pageNumber - 1,
                        pdfX: pdfX,
                        pdfY: pdfY,
                        pdfWidth: pdfWidth,
                        pdfHeight: pdfHeight,
                        strokeColor: strokeColor,
                        strokeWidth: shapeStrokeWidth,
                        strokeTransparent: strokeTransparent,
                        fillColor: fillColor,
                        fillTransparent: fillTransparent,
                        opacity: shapeOpacityValue,
                        rotation: 0
                    };

                    annotations.push(annotation);
                    persistAnnotations();
                    updateAnnotationsList();

                    drawingShape.element.remove();
                    const pageInfo = {
                        scale: currentScale,
                        canvasHeight: canvas.height,
                    };
                    addAnnotationElement(wrapper, annotation, pageInfo);
                    setSelection(annotation);
                    setStatus('Shape added. Click Save to keep changes.', 'ok');

                    drawingShape = null;
                });
                
                wrapper.appendChild(canvas);
                wrapper.appendChild(overlay);
                viewer.appendChild(wrapper);
                
                const pageInfo = {
                    scale: currentScale,
                    canvasHeight: canvas.height,
                };

                annotations
                    .filter((annotation) => annotation.pageIndex === pageNumber - 1)
                    .forEach((annotation) => addAnnotationElement(wrapper, annotation, pageInfo));

                // Render editable text fields - pass the actual viewport for accurate scaling
                const pageData = overlayExtractionData.find(p => p.page_number === pageNumber);
                if (pageData && pageData.words) {
                    renderOverlayFields(overlay, pageData, viewport, canvas);
                }
            }
            
            const getOverlayStoredEdit = (key) =>
                overlayEditedFields.get(key) || overlayPersistedEdits.get(key);

            function renderOverlayFields(overlay, pageData, viewport, canvas) {
                if (!pageData.words || pageData.words.length === 0) return;

                const getCssFontFamily = (fontName) => {
                    if (!fontName) return 'sans-serif';
                    
                    let cleaned = fontName;
                    
                    // Remove PDF font prefixes (e.g., "ABCDEF+FontName")
                    if (cleaned.includes('+')) {
                        const parts = cleaned.split('+', 2);
                        if (parts[0].length === 6) {
                            cleaned = parts[1];
                        }
                    }
                    
                    // Strip 6-character uppercase prefix at the beginning
                    if (/^[A-Z]{6}[A-Z]/.test(cleaned)) {
                        cleaned = cleaned.substring(6);
                    }
                    
                    // Common PDF font mappings to system fonts
                    const fontMappings = {
                        'TimesNewRoman': 'Times New Roman, Times, serif',
                        'Times': 'Times New Roman, Times, serif',
                        'TimesRoman': 'Times New Roman, Times, serif',
                        'Arial': 'Arial, Helvetica, sans-serif',
                        'ArialMT': 'Arial, Helvetica, sans-serif',
                        'Helvetica': 'Helvetica, Arial, sans-serif',
                        'Courier': 'Courier New, Courier, monospace',
                        'CourierNew': 'Courier New, Courier, monospace',
                        'Calibri': 'Calibri, sans-serif',
                        'Verdana': 'Verdana, sans-serif',
                        'Georgia': 'Georgia, serif',
                        'Palatino': 'Palatino, Palatino Linotype, serif',
                        'Garamond': 'Garamond, serif',
                        'BookmanOldStyle': 'Bookman Old Style, serif',
                        'ComicSansMS': 'Comic Sans MS, cursive',
                        'Impact': 'Impact, Charcoal, sans-serif',
                        'LucidaConsole': 'Lucida Console, Monaco, monospace',
                        'TrebuchetMS': 'Trebuchet MS, sans-serif'
                    };
                    
                    // Extract base family name (remove style suffixes like -Bold, -Italic, etc.)
                    const baseName = cleaned.split(/[-_,]/)[0] || cleaned;
                    
                    // Check if we have a direct mapping
                    for (const [key, value] of Object.entries(fontMappings)) {
                        if (baseName.toLowerCase() === key.toLowerCase()) {
                            return value;
                        }
                    }
                    
                    // Return with fallback
                    if (baseName.toLowerCase().includes('serif') && !baseName.toLowerCase().includes('sans')) {
                        return `"${baseName}", serif`;
                    } else if (baseName.toLowerCase().includes('mono') || baseName.toLowerCase().includes('courier')) {
                        return `"${baseName}", monospace`;
                    } else {
                        return `"${baseName}", Arial, sans-serif`;
                    }
                };

                const loadOverlayFonts = (words) => {
                    const families = new Set();
                    words.forEach((word) => {
                        if (!word.font) return;
                        let cleaned = word.font;
                        if (cleaned.includes('+')) {
                            const parts = cleaned.split('+', 2);
                            if (parts[0].length === 6) {
                                cleaned = parts[1];
                            }
                        }
                        
                        // Strip 6-character uppercase prefix at the beginning
                        if (/^[A-Z]{6}[A-Z]/.test(cleaned)) {
                            cleaned = cleaned.substring(6);
                        }
                        
                        const family = cleaned.split(/[-_]/)[0] || cleaned;
                        if (family) {
                            families.add(family);
                        }
                    });

                    families.forEach((family) => {
                        if (overlayLoadedFonts.has(family)) return;
                        overlayLoadedFonts.add(family);
                        const link = document.createElement('link');
                        link.rel = 'stylesheet';
                        link.href = `https://fonts.googleapis.com/css2?family=${encodeURIComponent(family)}:ital,wght@0,400;0,700;1,400;1,700&display=swap`;
                        document.head.appendChild(link);
                    });
                };

                loadOverlayFonts(pageData.words);

                const measureCtx = document.createElement('canvas').getContext('2d');
                const measureTextWidth = (text, fontSizePx, fontFamily, fontWeight, fontStyle) => {
                    const weight = fontWeight || '400';
                    const style = fontStyle || 'normal';
                    measureCtx.font = `${style} ${weight} ${fontSizePx}px ${fontFamily}`;
                    const metrics = measureCtx.measureText(text || '');
                    return metrics.width || 0;
                };

                // Load fonts for blocks too
                if (pageData.blocks) {
                    const blockFonts = new Set();
                    pageData.blocks.forEach((block) => {
                        if (!block.font) return;
                        let cleaned = block.font;
                        if (cleaned.includes('+')) {
                            const parts = cleaned.split('+', 2);
                            if (parts[0].length === 6) {
                                cleaned = parts[1];
                            }
                        }
                        
                        // Strip 6-character uppercase prefix at the beginning
                        if (/^[A-Z]{6}[A-Z]/.test(cleaned)) {
                            cleaned = cleaned.substring(6);
                        }
                        
                        const family = cleaned.split(/[-_]/)[0] || cleaned;
                        if (family && !overlayLoadedFonts.has(family)) {
                            overlayLoadedFonts.add(family);
                            const link = document.createElement('link');
                            link.rel = 'stylesheet';
                            link.href = `https://fonts.googleapis.com/css2?family=${encodeURIComponent(family)}:ital,wght@0,400;0,700;1,400;1,700&display=swap`;
                            document.head.appendChild(link);
                        }
                    });
                }

                const getWordColor = (word, fallbackColor) => {
                    if (word && word.hex_color) {
                        return word.hex_color;
                    }
                    if (word && word.color !== undefined && word.color !== null) {
                        if (typeof word.color === 'number') {
                            return '#' + word.color.toString(16).padStart(6, '0');
                        }
                        if (typeof word.color === 'string') {
                            return word.color;
                        }
                    }
                    return fallbackColor || '#000000';
                };

                const inferFontWeightFromName = (fontName) => {
                    if (!fontName) return null;
                    const lower = fontName.toLowerCase();
                    
                    // Check for specific weight names in order of specificity
                    // Most specific first to avoid partial matches
                    if (lower.includes('hairline')) return '100';
                    if (lower.includes('thin')) return '100';
                    if (lower.includes('extralight') || lower.includes('ultralight')) return '200';
                    if (lower.includes('light')) return '300';
                    if (lower.includes('regular') || lower.includes('book') || lower.includes('normal')) return '400';
                    if (lower.includes('medium')) return '500';
                    if (lower.includes('semibold') || lower.includes('demibold')) return '600';
                    if (lower.includes('extrabold') || lower.includes('ultrabold')) return '800';
                    if (lower.includes('black') || lower.includes('heavy')) return '900';
                    if (lower.includes('bold')) return '700';
                    
                    return null;
                };

                const computeLetterSpacing = (word, scaleX, scaleY, fontFamily, fontWeight, fontStyle) => {
                    if (!word || !word.text || word.text.length < 2 || !word.width) {
                        return null;
                    }
                    const fontSizePx = (word.font_size * scaleY);
                    const measured = measureTextWidth(word.text, fontSizePx, fontFamily, fontWeight, fontStyle);
                    if (!measured) {
                        return null;
                    }
                    const target = word.width * scaleX;
                    const spacing = (target - measured) / (word.text.length - 1);
                    if (!Number.isFinite(spacing)) {
                        return null;
                    }
                    const clamped = Math.max(-1, Math.min(5, spacing));
                    return clamped;
                };

                const buildStyledWordSpans = (container, words, scaleX, scaleY, blockLeft, blockTop) => {
                    container.style.position = 'relative';
                    const lines = new Map();
                    words.forEach((word) => {
                        const lineKey = word.line_num ?? 0;
                        if (!lines.has(lineKey)) {
                            lines.set(lineKey, []);
                        }
                        lines.get(lineKey).push(word);
                    });

                    const sortedLineKeys = Array.from(lines.keys()).sort((a, b) => a - b);
                    sortedLineKeys.forEach((lineKey) => {
                        const lineWords = lines.get(lineKey).sort((a, b) => a.left - b.left);
                        lineWords.forEach((word, wordIndex) => {
                            const wordSpan = document.createElement('span');
                            wordSpan.textContent = word.text;
                            const wordFontFamily = getCssFontFamily(word.font);
                            
                            // Use explicit font_weight from extraction if available, otherwise infer
                            let wordWeight;
                            if (word.font_weight) {
                                wordWeight = String(word.font_weight);
                            } else {
                                const inferredWeight = inferFontWeightFromName(word.font);
                                if (inferredWeight) {
                                    wordWeight = inferredWeight;
                                } else if (word.bold) {
                                    wordWeight = '700';
                                } else {
                                    wordWeight = '400';
                                }
                            }
                            
                            const wordStyle = word.italic ? 'italic' : 'normal';
                            wordSpan.style.fontFamily = wordFontFamily;
                            wordSpan.style.fontSize = (word.font_size * scaleY) + 'px';
                            wordSpan.style.fontWeight = wordWeight;
                            wordSpan.style.fontStyle = wordStyle;
                            wordSpan.style.color = getWordColor(word, '#000000');
                            wordSpan.style.position = 'absolute';
                            wordSpan.style.left = ((word.left - blockLeft) * scaleX) + 'px';
                            wordSpan.style.top = ((word.top - blockTop) * scaleY) + 'px';
                            wordSpan.style.whiteSpace = 'pre';
                            const spacing = computeLetterSpacing(word, scaleX, scaleY, wordFontFamily, wordWeight, wordStyle);
                            if (spacing !== null) {
                                wordSpan.style.letterSpacing = spacing + 'px';
                            }
                            container.appendChild(wordSpan);
                            if (wordIndex < lineWords.length - 1) {
                                const space = document.createElement('span');
                                space.textContent = ' ';
                                space.style.position = 'absolute';
                                space.style.left = ((word.left - blockLeft + word.width) * scaleX) + 'px';
                                space.style.top = ((word.top - blockTop) * scaleY) + 'px';
                                space.style.fontSize = (word.font_size * scaleY) + 'px';
                                space.style.fontFamily = wordFontFamily;
                                space.style.fontWeight = wordWeight;
                                space.style.fontStyle = wordStyle;
                                container.appendChild(space);
                            }
                        });
                    });
                };

                const buildBlockRichHtml = (textSpan, fontFamily, fontWeight, fontStyle, fontSizePx, lineHeightPx, textColor) => {
                    const cloned = textSpan.cloneNode(true);
                    cloned.removeAttribute('contenteditable');
                    
                    // Don't override styles on the wrapper - let individual spans keep their own styles
                    const wrapperStyle = `position:relative;width:100%;height:100%;font-size:${fontSizePx};${lineHeightPx ? `line-height:${lineHeightPx};` : ''}color:${textColor};`;
                    
                    return `<div style="${wrapperStyle}">${cloned.innerHTML}</div>`;
                };

                const getWordBounds = (words) => {
                    let minLeft = Infinity;
                    let minTop = Infinity;
                    let maxRight = -Infinity;
                    let maxBottom = -Infinity;
                    words.forEach((word) => {
                        minLeft = Math.min(minLeft, word.left);
                        minTop = Math.min(minTop, word.top);
                        maxRight = Math.max(maxRight, word.left + word.width);
                        maxBottom = Math.max(maxBottom, word.top + word.height);
                    });
                    if (!Number.isFinite(minLeft) || !Number.isFinite(minTop) || !Number.isFinite(maxRight) || !Number.isFinite(maxBottom)) {
                        return null;
                    }
                    return {
                        left: minLeft,
                        top: minTop,
                        width: Math.max(1, maxRight - minLeft),
                        height: Math.max(1, maxBottom - minTop)
                    };
                };

                const renderOverlayBlocks = () => {
                    if (!pageData.blocks || pageData.blocks.length === 0) {
                        console.warn('No block data available for block mode');
                        return;
                    }
                    const effectiveWidth = canvas.width || 1;
                    const effectiveHeight = canvas.height || 1;
                    const scaleX = pageData.width ? effectiveWidth / pageData.width : 1;
                    const scaleY = pageData.height ? effectiveHeight / pageData.height : 1;

                    // Sort blocks by vertical position (top to bottom) then horizontal (left to right)
                    const sortedBlocks = [...pageData.blocks].sort((a, b) => {
                        const topDiff = a.top - b.top;
                        if (Math.abs(topDiff) > 5) return topDiff;
                        return a.left - b.left;
                    });

                    sortedBlocks.forEach((block, blockIndex) => {
                        const key = `block-${pageData.page_number}-${block.block_num}`;
                        const storedEdit = getOverlayStoredEdit(key);
                        const blockText = (block.text_lines && block.text_lines.length)
                            ? block.text_lines.join('\n')
                            : (block.text || '');
                        if (storedEdit && storedEdit.new_text === '') {
                            return;
                        }

                        let blockLeft = block.left;
                        let blockTop = block.top;
                        let blockWidth = block.width;
                        let blockHeight = block.height;

                        const field = document.createElement('div');
                        field.className = 'overlay-field';
                        field.style.position = 'absolute';
                        field.style.background = 'rgba(77, 208, 168, 0.15)';
                        field.style.pointerEvents = 'auto';
                        field.style.cursor = 'move';
                        field.style.padding = '0';
                        field.style.minWidth = '40px';
                        field.style.minHeight = '20px';
                        field.style.boxSizing = 'border-box';
                        field.style.overflow = 'hidden';

                        // Render the text content
                        const textSpan = document.createElement('div');
                        textSpan.contentEditable = true;
                        const hasStoredEdit = storedEdit && storedEdit.new_text != null;
                        textSpan.textContent = '';
                        textSpan.style.display = 'block';
                        textSpan.style.whiteSpace = 'pre-wrap';
                        textSpan.style.wordBreak = 'break-word';
                        textSpan.style.width = '100%';
                        textSpan.style.minHeight = '100%';
                        textSpan.style.outline = 'none';
                        textSpan.style.cursor = 'text';
                        textSpan.style.userSelect = 'text';
                        textSpan.style.padding = '0';
                        textSpan.style.margin = '0';
                        
                        // Apply font styling from block data directly to the field for inheritance
                        const fontFamily = getCssFontFamily(block.font);
                        
                        // Use explicit font_weight from extraction if available, otherwise infer
                        let fontWeight;
                        if (block.font_weight) {
                            fontWeight = String(block.font_weight);
                        } else {
                            const inferredWeight = inferFontWeightFromName(block.font);
                            if (inferredWeight) {
                                fontWeight = inferredWeight;
                            } else if (block.bold) {
                                fontWeight = '700';
                            } else {
                                fontWeight = '400';
                            }
                        }
                        
                        const fontStyle = block.italic ? 'italic' : 'normal';
                        const fontSize = (block.font_size * scaleY) + 'px';
                        const lineHeightValue = block.avg_line_height || block.line_height;
                        const lineHeightPx = lineHeightValue ? (lineHeightValue * scaleY) + 'px' : '';
                        
                        // Debug logging for first block
                        if (blockIndex === 0) {
                            console.log('Block font info:', {
                                original_font: block.font,
                                css_family: fontFamily,
                                weight: fontWeight,
                                style: fontStyle,
                                size_pdf: block.font_size,
                                size_scaled: fontSize,
                                scaleY: scaleY,
                                bold: block.bold,
                                italic: block.italic
                            });
                        }
                        
                        // Apply to both field and textSpan for proper inheritance
                        field.style.fontFamily = fontFamily;
                        field.style.fontWeight = fontWeight;
                        field.style.fontStyle = fontStyle;
                        field.style.fontSize = fontSize;
                        
                        textSpan.style.fontFamily = fontFamily;
                        textSpan.style.fontWeight = fontWeight;
                        textSpan.style.fontStyle = fontStyle;
                        textSpan.style.fontSize = fontSize;
                        
                        // Apply text color
                        let textColor = '#000000';
                        if (block.hex_color) {
                            textColor = block.hex_color;
                        } else if (block.color !== undefined && block.color !== null) {
                            textColor = typeof block.color === 'number'
                                ? '#' + block.color.toString(16).padStart(6, '0')
                                : block.color;
                        }
                        field.style.color = textColor;
                        textSpan.style.color = textColor;
                        field.dataset.textColor = textColor;
                        field.dataset.originalTextColor = textColor;
                        field.dataset.fontWeight = fontWeight;
                        field.dataset.fontStyle = fontStyle;
                        field.dataset.originalFontWeight = fontWeight;
                        field.dataset.originalFontStyle = fontStyle;

                        const blockWords = pageData.words
                            ? pageData.words.filter((word) => word.block_num === block.block_num)
                            : [];

                        if (!storedEdit && blockWords.length > 0) {
                            const bounds = getWordBounds(blockWords);
                            if (bounds) {
                                blockLeft = bounds.left;
                                blockTop = bounds.top;
                                blockWidth = bounds.width;
                                blockHeight = bounds.height;
                            }
                        }

                        if (hasStoredEdit) {
                            textSpan.textContent = storedEdit.new_text;
                        } else if (blockWords.length > 0) {
                            buildStyledWordSpans(textSpan, blockWords, scaleX, scaleY, blockLeft, blockTop);
                        } else {
                            textSpan.textContent = blockText;
                        }
                        
                        if (lineHeightPx) {
                            textSpan.style.lineHeight = lineHeightPx;
                        }
                        
                        field.appendChild(textSpan);

                        // Apply positioning from PyMuPDF data
                        const padding = 0;
                        const baseLeft = storedEdit ? storedEdit.bbox[0] : blockLeft;
                        const baseTop = storedEdit ? storedEdit.bbox[1] : blockTop;
                        const baseWidth = storedEdit ? (storedEdit.bbox[2] - storedEdit.bbox[0]) : blockWidth;
                        const baseHeight = storedEdit ? (storedEdit.bbox[3] - storedEdit.bbox[1]) : blockHeight;

                        field.style.left = ((baseLeft * scaleX) - padding) + 'px';
                        field.style.top = ((baseTop * scaleY) - padding) + 'px';
                        field.style.width = ((baseWidth * scaleX) + (padding * 2)) + 'px';
                        field.style.height = ((baseHeight * scaleY) + (padding * 2)) + 'px';
                        field.style.zIndex = blockIndex + 1;

                        if (!storedEdit && blockWords.length > 0) {
                            const bounds = getWordBounds(blockWords);
                            if (bounds) {
                                const expectedLeft = (bounds.left * scaleX) - padding;
                                const expectedTop = (bounds.top * scaleY) - padding;
                                const expectedWidth = (bounds.width * scaleX) + (padding * 2);
                                const expectedHeight = (bounds.height * scaleY) + (padding * 2);

                                field.style.left = expectedLeft + 'px';
                                field.style.top = expectedTop + 'px';
                                field.style.width = expectedWidth + 'px';
                                field.style.height = expectedHeight + 'px';
                            }
                        }

                        field.dataset.originalText = blockText;
                        field.dataset.originalLeft = blockLeft;
                        field.dataset.originalTop = blockTop;
                        field.dataset.originalWidth = blockWidth;
                        field.dataset.originalHeight = blockHeight;
                        field.dataset.originalOriginX = blockLeft;
                        field.dataset.originalOriginY = blockTop + blockHeight;
                        field.dataset.pageNumber = pageData.page_number;
                        field.dataset.wordIndex = key;
                        field.dataset.font = block.font;
                        field.dataset.fontSize = block.font_size;
                        if (block.font_xref !== undefined && block.font_xref !== null) {
                            field.dataset.fontXref = block.font_xref;
                        }
                        field.dataset.canvasWidth = canvas.width;
                        field.dataset.canvasHeight = canvas.height;
                        field.dataset.pageWidth = pageData.width;
                        field.dataset.pageHeight = pageData.height;
                        field.dataset.padding = 0;

                        // Add tooltip on hover to show what text is in this block
                        field.title = blockText;


                        // Track changes to the text content
                        textSpan.addEventListener('input', function() {
                            // Get the actual user-typed text (innerText preserves line breaks)
                            const userTypedText = textSpan.innerText;
                            const editKey = key;
                            const computedStyle = window.getComputedStyle(textSpan);
                            const currentFontSizePx = computedStyle.fontSize;
                            const currentLineHeightPx = computedStyle.lineHeight !== 'normal' ? computedStyle.lineHeight : '';
                            const currentFontSizePdf = parseFloat(field.dataset.fontSize || (parseFloat(currentFontSizePx) / scaleY) || block.font_size);
                            const richHtml = buildBlockRichHtml(textSpan, fontFamily, fontWeight, fontStyle, currentFontSizePx, currentLineHeightPx, textColor);
                            
                            // Create or update the edit record with CORRECT field mapping
                            const currentLeft = parseFloat(field.style.left) / scaleX;
                            const currentTop = parseFloat(field.style.top) / scaleY;
                            const currentWidth = parseFloat(field.style.width) / scaleX;
                            const currentHeight = parseFloat(field.style.height) / scaleY;
                            
                            // Use CURRENT position for origin (where text should be inserted)
                            const currentOriginX = currentLeft;
                            const currentOriginY = currentTop + currentHeight;
                            
                            overlayEditedFields.set(editKey, {
                                page_number: pageData.page_number,
                                block_num: block.block_num,
                                original_text: blockText,           // ORIGINAL PDF TEXT
                                new_text: userTypedText,            // USER TYPED TEXT
                                rich_html: richHtml,
                                bbox: [currentLeft, currentTop, currentLeft + currentWidth, currentTop + currentHeight],
                                original_bbox: [blockLeft, blockTop, blockLeft + blockWidth, blockTop + blockHeight],
                                origin_x: currentOriginX,           // CURRENT POSITION X
                                origin_y: currentOriginY,           // CURRENT POSITION Y (bottom)
                                font: block.font,                   // FONT NAME FROM PDF
                                font_size: currentFontSizePdf,      // CURRENT FONT SIZE
                                font_xref: block.font_xref,         // FONT REFERENCE
                                line_height: lineHeightValue || null,
                                color: field.dataset.textColor || '#000000'  // TEXT COLOR
                            });
                            
                            persistOverlayEdits();
                            updateOverlaySaveButton();
                            pushUndoState();
                        });
                        
                        // Save undo state when user starts editing
                        textSpan.addEventListener('focus', function() {
                            setOverlaySelection(field);
                            pushUndoState();
                        });
                        textSpan.addEventListener('mouseup', function() {
                            setOverlaySelection(field);
                        });
                        textSpan.addEventListener('keyup', function() {
                            setOverlaySelection(field);
                        });
                        textSpan.addEventListener('focus', function() {
                            field.classList.add('active');
                        });
                        textSpan.addEventListener('blur', function(e) {
                            // Delay removing active class to allow button clicks to register
                            setTimeout(() => {
                                // Only remove if not clicking on handles
                                if (!field.contains(document.activeElement)) {
                                    field.classList.remove('active');
                                }
                            }, 150);
                        });
                        
                        // Add drag-to-move functionality
                        (function() {
                            let isDragging = false;
                            let dragStart = { x: 0, y: 0 };
                            
                            // Create centered move handle
                            const moveHandle = document.createElement('div');
                            moveHandle.className = 'move-handle';
                            moveHandle.innerHTML = '✥';
                            moveHandle.title = 'Drag to move';
                            moveHandle.style.pointerEvents = 'auto';
                            field.appendChild(moveHandle);
                            
                            // Create delete handle
                            const deleteHandle = document.createElement('div');
                            deleteHandle.className = 'delete-handle';
                            deleteHandle.innerHTML = '🗑';
                            deleteHandle.title = 'Delete this text block';
                            deleteHandle.style.pointerEvents = 'auto';
                            field.appendChild(deleteHandle);
                            
                            deleteHandle.addEventListener('click', function(e) {
                                console.log('click');
                                e.preventDefault();
                                e.stopPropagation();
                                
                                // Save state before deletion
                                pushUndoState();
                                
                                // Mark as edited by storing empty text
                                const deleteKey = field.dataset.wordIndex || key;
                                const originalText = field.dataset.originalText || '';
                                if (deleteKey) {
                                    const computedStyle = window.getComputedStyle(textSpan);
                                    const currentFontSizePx = computedStyle.fontSize;
                                    const currentLineHeightPx = computedStyle.lineHeight !== 'normal' ? computedStyle.lineHeight : '';
                                    const currentFontSizePdf = parseFloat(field.dataset.fontSize || (parseFloat(currentFontSizePx) / scaleY) || block.font_size);
                                    const richHtml = buildBlockRichHtml(textSpan, fontFamily, fontWeight, fontStyle, currentFontSizePx, currentLineHeightPx, textColor);
                                    overlayEditedFields.set(deleteKey, {
                                        page_number: pageData.page_number,
                                        block_num: block.block_num,
                                        original_text: originalText,
                                        new_text: '',
                                        rich_html: richHtml,
                                        original_bbox: [blockLeft, blockTop, blockLeft + blockWidth, blockTop + blockHeight],
                                        bbox: [blockLeft, blockTop, blockLeft + blockWidth, blockTop + blockHeight],
                                    font_xref: field.dataset.fontXref ? parseInt(field.dataset.fontXref, 10) : null,
                                    font: block.font,
                                    font_size: currentFontSizePdf,
                                    line_height: lineHeightValue || null,
                                    color: field.dataset.textColor || '#000000'
                                });
                                }
                                
                                // Clear selection immediately
                                selectedOverlayField = null;
                                console.log(field.parentNode);
                                // Remove the entire field element (including bounding box) completely
                                if (field.parentNode) {
                                    field.parentNode.removeChild(field);
                                }
                                
                                updateOverlaySaveButton();
                                persistOverlayEdits();
                                setStatus('Text block deleted', 'ok');
                            });
                            
                            moveHandle.addEventListener('mousedown', function(e) {
                                // Save state before drag starts
                                pushUndoState();
                                
                                isDragging = true;
                                dragStart = { x: e.clientX, y: e.clientY };
                                field.style.cursor = 'move';
                                textSpan.style.pointerEvents = 'none'; // Prevent text selection during drag
                                moveHandle.style.cursor = 'grabbing';
                                e.preventDefault();
                                e.stopPropagation();
                            });
                            
                            const moveHandler = function(e) {
                                if (!isDragging) return;
                                
                                const dx = e.clientX - dragStart.x;
                                const dy = e.clientY - dragStart.y;
                                
                                const currentLeft = parseFloat(field.style.left);
                                const currentTop = parseFloat(field.style.top);
                                
                                field.style.left = (currentLeft + dx) + 'px';
                                field.style.top = (currentTop + dy) + 'px';
                                
                                dragStart = { x: e.clientX, y: e.clientY };
                            };
                            
                            const upHandler = function() {
                                if (isDragging) {
                                    isDragging = false;
                                    field.style.cursor = 'move';
                                    moveHandle.style.cursor = 'move';
                                    textSpan.style.pointerEvents = 'auto'; // Re-enable text interaction
                                    
                                    // Save the new position
                                    const newLeft = parseFloat(field.style.left) / scaleX;
                                    const newTop = parseFloat(field.style.top) / scaleY;
                                    const width = parseFloat(field.style.width) / scaleX;
                                    const height = parseFloat(field.style.height) / scaleY;
                                    
                                    // Calculate NEW origin based on the NEW position (not original!)
                                    // origin_x is the left edge, origin_y is the bottom edge (baseline area)
                                    const newOriginX = newLeft;
                                    const newOriginY = newTop + height;
                                    
                                    // Get current text - preserve original formatting if content unchanged
                                    let currentText = textSpan.innerText;
                                    // Compare without whitespace to detect if user actually changed content
                                    const originalClean = blockText.replace(/\s+/g, '');
                                    const currentClean = currentText.replace(/\s+/g, '');
                                    // If content is same (just whitespace differences), use original to preserve \n
                                    if (originalClean === currentClean) {
                                        currentText = blockText;
                                    }
                                    const computedStyle = window.getComputedStyle(textSpan);
                                    const currentFontSizePx = computedStyle.fontSize;
                                    const currentLineHeightPx = computedStyle.lineHeight !== 'normal' ? computedStyle.lineHeight : '';
                                    const currentFontSizePdf = parseFloat(field.dataset.fontSize || (parseFloat(currentFontSizePx) / scaleY) || block.font_size);
                                    const richHtml = buildBlockRichHtml(textSpan, fontFamily, fontWeight, fontStyle, currentFontSizePx, currentLineHeightPx, textColor);
                                    
                                    overlayEditedFields.set(key, {
                                        page_number: pageData.page_number,
                                        block_num: block.block_num,
                                        original_text: blockText,              // ORIGINAL PDF TEXT
                                        new_text: currentText,                 // TEXT WITH PRESERVED NEWLINES
                                        rich_html: richHtml,
                                        bbox: [newLeft, newTop, newLeft + width, newTop + height],
                                        original_bbox: [blockLeft, blockTop, blockLeft + blockWidth, blockTop + blockHeight],
                                        origin_x: newOriginX,                  // NEW POSITION X
                                        origin_y: newOriginY,                  // NEW POSITION Y (bottom of box)
                                        font: block.font,                      // FONT NAME
                                        font_size: currentFontSizePdf,         // FONT SIZE
                                        font_xref: block.font_xref,            // FONT REFERENCE
                                        color: field.dataset.textColor || '#000000'  // TEXT COLOR
                                    });
                                    
                                    persistOverlayEdits();
                                    updateOverlaySaveButton();
                                }
                            };
                            
                            document.addEventListener('mousemove', moveHandler);
                            document.addEventListener('mouseup', upHandler);
                        })();
                        
                        // Add resize handles
                        const resizePositions = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
                        resizePositions.forEach(pos => {
                            const handle = document.createElement('div');
                            handle.className = `resize-handle ${pos} ${['n','s','e','w'].includes(pos) ? 'edge' : 'corner'}`;
                            field.appendChild(handle);
                        });
                        
                        // Resize functionality
                        field.addEventListener('mousedown', (e) => {
                            if (!e.target.classList.contains('resize-handle')) return;
                            
                            e.preventDefault();
                            e.stopPropagation();
                            
                            pushUndoState();
                            
                            const handle = e.target;
                            const position = handle.classList[1]; // nw, n, ne, e, se, s, sw, w
                            
                            const startX = e.clientX;
                            const startY = e.clientY;
                            const startLeft = parseFloat(field.style.left);
                            const startTop = parseFloat(field.style.top);
                            const startWidth = parseFloat(field.style.width);
                            const startHeight = parseFloat(field.style.height);
                            const startStyle = window.getComputedStyle(textSpan);
                            const startFontSizePx = parseFloat(startStyle.fontSize) || 12;
                            const startLineHeightPx = startStyle.lineHeight !== 'normal' ? parseFloat(startStyle.lineHeight) : null;
                            const sizedSpans = Array.from(textSpan.querySelectorAll('span')).map(span => {
                                const spanStyle = window.getComputedStyle(span);
                                const spanFontSizePx = parseFloat(spanStyle.fontSize) || startFontSizePx;
                                const spanLineHeightPx = spanStyle.lineHeight !== 'normal' ? parseFloat(spanStyle.lineHeight) : null;
                                return { span, spanFontSizePx, spanLineHeightPx };
                            });
                            
                            const normalizeTextForWrap = () => {
                                const hasPositionedSpans = textSpan.querySelector('span') &&
                                    Array.from(textSpan.querySelectorAll('span')).some(span => span.style.position === 'absolute');
                                if (!hasPositionedSpans) {
                                    return;
                                }
                                const plainText = textSpan.innerText;
                                textSpan.textContent = plainText;
                                textSpan.style.whiteSpace = 'pre-wrap';
                                textSpan.style.wordBreak = 'break-word';
                                textSpan.style.width = '100%';
                                textSpan.style.height = '100%';
                            };

                            const onMouseMove = (moveEvent) => {
                                const dx = moveEvent.clientX - startX;
                                const dy = moveEvent.clientY - startY;
                                
                                let newLeft = startLeft;
                                let newTop = startTop;
                                let newWidth = startWidth;
                                let newHeight = startHeight;
                                
                                // Handle horizontal resize
                                if (position.includes('w')) {
                                    newLeft = startLeft + dx;
                                    newWidth = startWidth - dx;
                                } else if (position.includes('e')) {
                                    newWidth = startWidth + dx;
                                }
                                
                                // Handle vertical resize
                                if (position.includes('n')) {
                                    newTop = startTop + dy;
                                    newHeight = startHeight - dy;
                                } else if (position.includes('s')) {
                                    newHeight = startHeight + dy;
                                }
                                
                                // Enforce minimum size
                                if (newWidth < 40) {
                                    if (position.includes('w')) {
                                        newLeft = startLeft + startWidth - 40;
                                    }
                                    newWidth = 40;
                                }
                                if (newHeight < 20) {
                                    if (position.includes('n')) {
                                        newTop = startTop + startHeight - 20;
                                    }
                                    newHeight = 20;
                                }
                                
                                field.style.left = newLeft + 'px';
                                field.style.top = newTop + 'px';
                                field.style.width = newWidth + 'px';
                                field.style.height = newHeight + 'px';

                                if (sizedSpans.length > 0) {
                                    sizedSpans.forEach(({ span, spanFontSizePx, spanLineHeightPx }) => {
                                        span.style.fontSize = spanFontSizePx + 'px';
                                        if (spanLineHeightPx) {
                                            span.style.lineHeight = spanLineHeightPx + 'px';
                                        }
                                    });
                                }
                                normalizeTextForWrap();
                            };
                            
                            const onMouseUp = () => {
                                document.removeEventListener('mousemove', onMouseMove);
                                document.removeEventListener('mouseup', onMouseUp);
                                
                                // Save the new dimensions
                                const newLeft = parseFloat(field.style.left) / scaleX;
                                const newTop = parseFloat(field.style.top) / scaleY;
                                const newWidth = parseFloat(field.style.width) / scaleX;
                                const newHeight = parseFloat(field.style.height) / scaleY;
                                
                                // Use CURRENT position for origin (where text should be inserted)
                                const newOriginX = newLeft;
                                const newOriginY = newTop + newHeight;
                                
                                // Get current text - preserve original formatting if content unchanged
                                let currentText = textSpan.innerText;
                                const originalClean = blockText.replace(/\s+/g, '');
                                const currentClean = currentText.replace(/\s+/g, '');
                                if (originalClean === currentClean) {
                                    currentText = blockText;
                                }
                                const computedStyle = window.getComputedStyle(textSpan);
                                const currentFontSizePx = computedStyle.fontSize;
                                const currentLineHeightPx = computedStyle.lineHeight !== 'normal' ? computedStyle.lineHeight : '';
                                const currentFontSizePdf = parseFloat(field.dataset.fontSize || (parseFloat(currentFontSizePx) / scaleY) || block.font_size);
                                const richHtml = buildBlockRichHtml(textSpan, fontFamily, fontWeight, fontStyle, currentFontSizePx, currentLineHeightPx, textColor);
                                
                                overlayEditedFields.set(key, {
                                    page_number: pageData.page_number,
                                    block_num: block.block_num,
                                    original_text: blockText,                   // ORIGINAL PDF TEXT
                                    new_text: currentText,                      // TEXT WITH PRESERVED NEWLINES
                                    rich_html: richHtml,
                                    bbox: [newLeft, newTop, newLeft + newWidth, newTop + newHeight],
                                    original_bbox: [blockLeft, blockTop, blockLeft + blockWidth, blockTop + blockHeight],
                                    origin_x: newOriginX,                       // NEW POSITION X
                                    origin_y: newOriginY,                       // NEW POSITION Y (bottom)
                                    font: block.font,                           // FONT NAME
                                    font_size: currentFontSizePdf,              // FONT SIZE
                                    font_xref: block.font_xref,                 // FONT REFERENCE
                                    line_height: lineHeightValue || null,
                                    color: field.dataset.textColor || '#000000' // TEXT COLOR
                                });
                                
                                persistOverlayEdits();
                                updateOverlaySaveButton();
                            };
                            
                            document.addEventListener('mousemove', onMouseMove);
                            document.addEventListener('mouseup', onMouseUp);
                        });

                        overlay.appendChild(field);
                    });
                };

                // Calculate scale factors based on the actual rendered canvas vs PDF page dimensions
                // pageData dimensions are from PyMuPDF (in PDF points)
                // canvas dimensions are from PDF.js rendering (in pixels at currentScale)
                const scaleX = canvas.width / pageData.width;
                const scaleY = canvas.height / pageData.height;
                
                console.log('Overlay field rendering:', {
                    canvasWidth: canvas.width,
                    canvasHeight: canvas.height,
                    pageDataWidth: pageData.width,
                    pageDataHeight: pageData.height,
                    scaleX,
                    scaleY,
                    currentScale,
                    sampleWord: pageData.words[0]
                });
                
                // Always use block mode
                renderOverlayBlocks();
                return;

                pageData.words.forEach((word, index) => {
                    const storedEdit = overlayEditedFields.get(`${pageData.page_number}-${index}`);
                    if (storedEdit && storedEdit.new_text === '') {
                        return;
                    }
                    const field = document.createElement('div');
                    field.className = 'overlay-field';
                    field.style.position = 'absolute';
                    field.style.background = 'rgba(77, 208, 168, 0.1)';
                    field.style.pointerEvents = 'auto';
                    field.style.cursor = 'text';
                    field.style.padding = '2px';
                    field.style.minWidth = '20px';
                    field.style.minHeight = '15px';
                    field.style.display = 'flex';
                    field.style.alignItems = 'center';
                    
                    const textSpan = document.createElement('span');
                    textSpan.contentEditable = true;
                    textSpan.textContent = storedEdit && storedEdit.new_text != null ? storedEdit.new_text : word.text;
                    
                    // Use the color from the PDF extraction (convert from integer to hex)
                    let textColor = '#000000';
                    console.log(`Word "${word.text}": color value=${word.color}, type=${typeof word.color}`);
                    if (word.color !== undefined && word.color !== null) {
                        if (typeof word.color === 'number') {
                            // Color is stored as an integer (RGB packed)
                            textColor = '#' + word.color.toString(16).padStart(6, '0');
                        } else if (typeof word.color === 'string') {
                            textColor = word.color;
                        }
                    }
                    // Debug: log non-black colors
                    if (textColor !== '#000000') {
                        console.log(`Color for "${word.text}": raw=${word.color}, converted=${textColor}`);
                    }
                    textSpan.style.color = textColor;
                    field.dataset.textColor = textColor;
                    
                    textSpan.style.outline = 'none';
                    textSpan.style.width = '100%';
                    textSpan.style.cursor = 'text';
                    textSpan.style.whiteSpace = 'nowrap';
                    textSpan.style.padding = '0';
                    textSpan.style.margin = '0';
                    textSpan.style.verticalAlign = 'baseline';
                    
                    // Map PDF fonts to web-safe fonts for preview (exact fonts used on save)
                    let fontFamily = 'Arial, sans-serif';
                    let fontWeight = 'normal';
                    let fontStyle = 'normal';
                    
                    if (word.font) {
                        const fontName = word.font.toLowerCase();
                        
                        // Detect weight
                        if (fontName.includes('bold') || fontName.includes('700') || fontName.includes('black') || fontName.includes('-b')) {
                            fontWeight = 'bold';
                        } else if (fontName.includes('semibold') || fontName.includes('600')) {
                            fontWeight = '600';
                        } else if (fontName.includes('medium') || fontName.includes('500')) {
                            fontWeight = '500';
                        }
                        
                        // Detect style
                        if (fontName.includes('italic') || fontName.includes('oblique') || fontName.includes('-i')) {
                            fontStyle = 'italic';
                        }
                        
                        // Map to web-safe font families
                        if (fontName.includes('gelasio') || fontName.includes('georgia') || fontName.includes('garamond')) {
                            fontFamily = 'Georgia, "Times New Roman", serif';
                        } else if (fontName.includes('times') || fontName.includes('serif')) {
                            fontFamily = '"Times New Roman", Times, serif';
                        } else if (fontName.includes('courier') || fontName.includes('mono')) {
                            fontFamily = '"Courier New", Courier, monospace';
                        } else if (fontName.includes('arimo') || fontName.includes('arial') || fontName.includes('helvetica')) {
                            fontFamily = 'Arial, Helvetica, sans-serif';
                        } else if (fontName.includes('roboto') || fontName.includes('sans')) {
                            fontFamily = 'Arial, sans-serif';
                        } else {
                            // Default to sans-serif for unknown fonts
                            fontFamily = 'Arial, sans-serif';
                        }
                    }
                    
                    textSpan.style.fontFamily = fontFamily;
                    textSpan.style.fontWeight = fontWeight;
                    textSpan.style.fontStyle = fontStyle;
                    
                    field.appendChild(textSpan);
                    
                    // Apply scaling to PDF coordinates to get canvas pixel positions
                    const padding = 4;
                    const baseLeft = storedEdit ? storedEdit.bbox[0] : word.left;
                    const baseTop = storedEdit ? storedEdit.bbox[1] : word.top;
                    const baseWidth = storedEdit ? (storedEdit.bbox[2] - storedEdit.bbox[0]) : word.width;
                    const baseHeight = storedEdit ? (storedEdit.bbox[3] - storedEdit.bbox[1]) : word.height;

                    const left = (baseLeft * scaleX) - padding;
                    const top = (baseTop * scaleY) - padding;
                    const width = (baseWidth * scaleX) + (padding * 2);
                    const height = (baseHeight * scaleY) + (padding * 2);
                    
                    field.style.left = left + 'px';
                    field.style.top = top + 'px';
                    field.style.width = width + 'px';
                    field.style.height = height + 'px';
                    field.style.boxSizing = 'border-box';
                    textSpan.style.fontSize = (word.font_size * scaleY) + 'px';
                    textSpan.style.fontFamily = getCssFontFamily(word.font);
                    textSpan.style.fontWeight = word.bold ? '700' : '400';
                    textSpan.style.fontStyle = word.italic ? 'italic' : 'normal';
                    textSpan.style.display = 'inline-block';
                    textSpan.style.transformOrigin = 'left baseline';
                    textSpan.style.lineHeight = '1';
                    textSpan.style.height = '100%';

                    const targetWidth = baseWidth * scaleX;
                    const measuredWidth = measureTextWidth(
                        word.text,
                        word.font_size * scaleY,
                        textSpan.style.fontFamily,
                        textSpan.style.fontWeight,
                        textSpan.style.fontStyle
                    );
                    if (measuredWidth > 0 && targetWidth > 0) {
                        const ratio = Math.max(0.7, Math.min(1.3, targetWidth / measuredWidth));
                        textSpan.style.transform = `scaleX(${ratio})`;
                    }
                    
                    if (index === 0) {
                        console.log('First field positioning:', {
                            wordLeft: word.left,
                            wordTop: word.top,
                            wordHeight: word.height,
                            scaledLeft: left,
                            scaledTop: top,
                            width,
                            height
                        });
                    }
                    
                    // Store original data
                    field.dataset.originalText = word.text;
                    field.dataset.originalLeft = word.left;
                    field.dataset.originalTop = word.top;
                    field.dataset.originalWidth = word.width;
                    field.dataset.originalHeight = word.height;
                    field.dataset.originalOriginX = word.origin_x ?? word.left;
                    field.dataset.originalOriginY = word.origin_y ?? (word.top + word.height);
                    field.dataset.pageNumber = pageData.page_number;
                    field.dataset.wordIndex = index;
                    field.dataset.font = word.font;
                    field.dataset.fontSize = word.font_size;
                    if (word.font_xref !== undefined && word.font_xref !== null) {
                        field.dataset.fontXref = word.font_xref;
                    }
                    field.dataset.canvasWidth = canvas.width;
                    field.dataset.canvasHeight = canvas.height;
                    field.dataset.pageWidth = pageData.width;
                    field.dataset.pageHeight = pageData.height;
                    field.dataset.padding = padding;
                    
                    // Track changes and auto-expand width as user types
                    textSpan.addEventListener('input', () => {
                        // Auto-expand field width to fit text content
                        const currentText = textSpan.textContent;
                        const currentFontSize = parseFloat(textSpan.style.fontSize) || 12;
                        const currentFontFamily = textSpan.style.fontFamily || 'sans-serif';
                        const currentFontWeight = textSpan.style.fontWeight || 'normal';
                        const currentFontStyle = textSpan.style.fontStyle || 'normal';
                        
                        const measuredWidth = measureTextWidth(
                            currentText,
                            currentFontSize,
                            currentFontFamily,
                            currentFontWeight,
                            currentFontStyle
                        );
                        
                        const currentPadding = parseFloat(field.dataset.padding || '4');
                        const minWidth = measuredWidth + (currentPadding * 2) + 10; // Add 10px buffer
                        const currentWidth = parseFloat(field.style.width);
                        
                        // Only expand width if needed (don't shrink)
                        if (minWidth > currentWidth) {
                            field.style.width = minWidth + 'px';
                        }
                        
                        trackOverlayFieldChange(field, textSpan, pageData, word, index);
                    });
                    
                    // Save undo state when user starts editing (before changes)
                    textSpan.addEventListener('focus', function() {
                        pushUndoState();
                    });
                    
                    // Add drag-to-move functionality
                    (function() {
                        let isDragging = false;
                        let dragStart = { x: 0, y: 0 };
                        
                        field.addEventListener('mousedown', function(e) {
                            // Don't drag if clicking on delete button, resize handles, or text input
                            if (e.target.classList.contains('overlay-resize-handle')) return;
                            if (e.target === textSpan) return;
                            
                            // Save state before drag starts
                            pushUndoState();
                            
                            isDragging = true;
                            dragStart = { x: e.clientX, y: e.clientY };
                            field.style.cursor = 'move';
                            e.preventDefault();
                            e.stopPropagation();
                        });
                        
                        const moveHandler = function(e) {
                            if (!isDragging) return;
                            
                            const dx = e.clientX - dragStart.x;
                            const dy = e.clientY - dragStart.y;
                            
                            const currentLeft = parseFloat(field.style.left);
                            const currentTop = parseFloat(field.style.top);
                            
                            field.style.left = (currentLeft + dx) + 'px';
                            field.style.top = (currentTop + dy) + 'px';
                            
                            dragStart = { x: e.clientX, y: e.clientY };
                        };
                        
                        const upHandler = function() {
                            if (isDragging) {
                                isDragging = false;
                                field.style.cursor = 'default';
                                // Track the position change
                                trackOverlayFieldChange(field, textSpan, pageData, word, index);
                            }
                        };
                        
                        document.addEventListener('mousemove', moveHandler);
                        document.addEventListener('mouseup', upHandler);
                    })();
                    
                    
                    // Add resize handles
                    const handles = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
                    handles.forEach((pos) => {
                        const handle = document.createElement('div');
                        handle.className = `resize-handle ${pos} ${['n','s','e','w'].includes(pos) ? 'edge' : 'corner'}`;
                        handle.dataset.position = pos;
                        handle.addEventListener('mousedown', startOverlayResize);
                        field.appendChild(handle);
                    });

                    // Drag to reposition
                    let isDragging = false;
                    let dragStart = { x: 0, y: 0 };

                    field.addEventListener('mousedown', (e) => {
                        if (e.target.classList.contains('resize-handle')) return;
                        if (e.target !== field && e.target !== textSpan) return;

                        isDragging = true;
                        dragStart = { x: e.clientX, y: e.clientY };
                        field.style.cursor = 'move';
                        textSpan.style.cursor = 'move';
                        e.preventDefault();
                        e.stopPropagation();
                    });

                    field.addEventListener('click', (e) => {
                        if (e.target.classList.contains('resize-handle')) return;
                        setOverlaySelection(field);
                    });

                    document.addEventListener('mousemove', (e) => {
                        if (!isDragging) return;
                        const dx = e.clientX - dragStart.x;
                        const dy = e.clientY - dragStart.y;
                        const currentLeft = parseFloat(field.style.left);
                        const currentTop = parseFloat(field.style.top);
                        field.style.left = (currentLeft + dx) + 'px';
                        field.style.top = (currentTop + dy) + 'px';
                        dragStart = { x: e.clientX, y: e.clientY };
                    });

                    document.addEventListener('mouseup', () => {
                        if (!isDragging) return;
                        isDragging = false;
                        field.style.cursor = 'text';
                        textSpan.style.cursor = 'text';
                        const originalWord = buildOriginalWordFromField(field, word.text);
                        trackOverlayFieldChange(field, textSpan, pageData, originalWord, index);
                    });

                    textSpan.addEventListener('dblclick', (e) => {
                        e.stopPropagation();
                        textSpan.style.cursor = 'text';
                        textSpan.focus();
                    });

                    overlay.appendChild(field);
                });
            }

            function buildOriginalWordFromField(field, fallbackText) {
                return {
                    text: field.dataset.originalText || fallbackText || '',
                    left: parseFloat(field.dataset.originalLeft || '0'),
                    top: parseFloat(field.dataset.originalTop || '0'),
                    width: parseFloat(field.dataset.originalWidth || '0'),
                    height: parseFloat(field.dataset.originalHeight || '0'),
                    origin_x: parseFloat(field.dataset.originalOriginX || field.dataset.originalLeft || '0'),
                    origin_y: parseFloat(field.dataset.originalOriginY || (parseFloat(field.dataset.originalTop || '0') + parseFloat(field.dataset.originalHeight || '0'))),
                    font: field.dataset.font || 'helv',
                    font_size: parseFloat(field.dataset.fontSize || '12')
                };
            }

            function startOverlayResize(e) {
                e.preventDefault();
                e.stopPropagation();

                overlayResizingField = e.target.closest('.overlay-field');
                overlayResizePosition = e.target.dataset.position;
                if (!overlayResizingField) return;

                const rect = overlayResizingField.getBoundingClientRect();
                const containerRect = overlayResizingField.parentElement.getBoundingClientRect();
                const textSpan = overlayResizingField.querySelector('[contenteditable]');
                const computedStyle = textSpan ? window.getComputedStyle(textSpan) : null;
                overlayResizeStart = {
                    x: e.clientX,
                    y: e.clientY,
                    width: rect.width,
                    height: rect.height,
                    left: rect.left - containerRect.left,
                    top: rect.top - containerRect.top,
                    fontSizePx: computedStyle ? parseFloat(computedStyle.fontSize) : null,
                    lineHeightPx: computedStyle && computedStyle.lineHeight !== 'normal'
                        ? parseFloat(computedStyle.lineHeight)
                        : null,
                    textSpan
                };

                if (textSpan) {
                    textSpan.contentEditable = false;
                }
                document.addEventListener('mousemove', doOverlayResize);
                document.addEventListener('mouseup', stopOverlayResize);
            }

            function doOverlayResize(e) {
                if (!overlayResizingField) return;

                const dx = e.clientX - overlayResizeStart.x;
                const dy = e.clientY - overlayResizeStart.y;

                let newLeft = overlayResizeStart.left;
                let newTop = overlayResizeStart.top;
                let newWidth = overlayResizeStart.width;
                let newHeight = overlayResizeStart.height;

                if (overlayResizePosition.includes('w')) {
                    newLeft = overlayResizeStart.left + dx;
                    newWidth = overlayResizeStart.width - dx;
                }
                if (overlayResizePosition.includes('e')) {
                    newWidth = overlayResizeStart.width + dx;
                }
                if (overlayResizePosition.includes('n')) {
                    newTop = overlayResizeStart.top + dy;
                    newHeight = overlayResizeStart.height - dy;
                }
                if (overlayResizePosition.includes('s')) {
                    newHeight = overlayResizeStart.height + dy;
                }

                if (newWidth < 20) newWidth = 20;
                if (newHeight < 15) newHeight = 15;

                overlayResizingField.style.left = newLeft + 'px';
                overlayResizingField.style.top = newTop + 'px';
                overlayResizingField.style.width = newWidth + 'px';
                overlayResizingField.style.height = newHeight + 'px';

                if (overlayResizeStart.textSpan && overlayResizeStart.fontSizePx) {
                    const scaleX = overlayResizeStart.width > 0 ? newWidth / overlayResizeStart.width : 1;
                    const scaleY = overlayResizeStart.height > 0 ? newHeight / overlayResizeStart.height : 1;
                    const scale = Math.max(0.2, Math.min(scaleX, scaleY));
                    const newFontSize = Math.max(6, overlayResizeStart.fontSizePx * scale);
                    overlayResizeStart.textSpan.style.fontSize = newFontSize + 'px';
                    if (overlayResizeStart.lineHeightPx) {
                        overlayResizeStart.textSpan.style.lineHeight = (overlayResizeStart.lineHeightPx * scale) + 'px';
                    }

                    const pageHeight = parseFloat(overlayResizingField.dataset.pageHeight || '0');
                    const canvasHeight = parseFloat(overlayResizingField.dataset.canvasHeight || '0');
                    const overlayEl = overlayResizingField.parentElement;
                    const overlayHeight = overlayEl ? overlayEl.clientHeight : 0;
                    const effectiveHeight = canvasHeight || overlayHeight || 1;
                    const scaleToPdf = pageHeight ? (effectiveHeight / pageHeight) : 1;
                    overlayResizingField.dataset.fontSize = String(newFontSize / scaleToPdf);
                }
            }

            function stopOverlayResize() {
                if (overlayResizingField) {
                    // Save undo state when resize completes
                    pushUndoState();
                    
                    const textSpan = overlayResizingField.querySelector('[contenteditable]');
                    if (textSpan) {
                        textSpan.contentEditable = true;
                    }
                    const pageNumber = parseInt(overlayResizingField.dataset.pageNumber, 10);
                    const index = parseInt(overlayResizingField.dataset.wordIndex, 10);
                    const originalWord = buildOriginalWordFromField(overlayResizingField, textSpan ? textSpan.textContent : '');
                    trackOverlayFieldChange(overlayResizingField, textSpan, { page_number: pageNumber }, originalWord, index);
                    overlayResizingField = null;
                }
                document.removeEventListener('mousemove', doOverlayResize);
                document.removeEventListener('mouseup', stopOverlayResize);
            }
            
            
            
            function trackOverlayFieldChange(field, textSpan, pageData, originalWord, index) {
                const key = typeof index === 'string' ? index : `${pageData.page_number}-${index}`;
                const currentText = textSpan.textContent.trim();

                // Get current position and size in browser pixels
                const currentLeft = parseFloat(field.style.left);
                const currentTop = parseFloat(field.style.top);
                const currentWidth = parseFloat(field.style.width);
                const currentHeight = parseFloat(field.style.height);

                // Get PDF dimensions and canvas dimensions for scale calculation
                const pageWidth = parseFloat(field.dataset.pageWidth || '0');
                const pageHeight = parseFloat(field.dataset.pageHeight || '0');
                const canvasWidth = parseFloat(field.dataset.canvasWidth || '0');
                const canvasHeight = parseFloat(field.dataset.canvasHeight || '0');
                const padding = parseFloat(field.dataset.padding || '0');

                const overlayEl = field.parentElement;
                const overlayWidth = overlayEl ? overlayEl.clientWidth : 0;
                const overlayHeight = overlayEl ? overlayEl.clientHeight : 0;
                const effectiveWidth = canvasWidth || overlayWidth || 1;
                const effectiveHeight = canvasHeight || overlayHeight || 1;

                // Calculate scale factors (pixels per PDF point)
                const scaleX = effectiveWidth / pageWidth;
                const scaleY = effectiveHeight / pageHeight;

                // Convert browser pixels to PDF points
                const pdfLeft = currentLeft / scaleX;
                const pdfTop = currentTop / scaleY;
                const pdfWidth = currentWidth / scaleX;
                const pdfHeight = currentHeight / scaleY;
                
                // Calculate baseline origin for text insertion
                const originDx = originalWord.origin_x - originalWord.left;
                const originDy = originalWord.origin_y - (originalWord.top + originalWord.height);
                const pdfOriginX = pdfLeft + originDx;
                const pdfOriginY = (pdfTop + pdfHeight) + originDy;

                const hasTextChanged = currentText !== originalWord.text;
                const hasPositionChanged = Math.abs(pdfLeft - originalWord.left) > 1 || Math.abs(pdfTop - originalWord.top) > 1;
                const hasSizeChanged = Math.abs(pdfWidth - originalWord.width) > 1 || Math.abs(pdfHeight - originalWord.height) > 1;

                if (hasTextChanged || hasPositionChanged || hasSizeChanged) {
                    const colorValue = field.dataset.textColor || '#000000';
                    const colorHex = colorValue.startsWith('#') ? colorValue : '#' + colorValue;
                    
                    const currentFontSizePdf = parseFloat(field.dataset.fontSize || String(originalWord.font_size || 12));
                    const editData = {
                        page_index: pageData.page_number - 1, // 0-based for Python
                        page_number: pageData.page_number,    // 1-based for display
                        original_text: originalWord.text,
                        new_text: currentText,
                        
                        // Browser coordinates (for debugging)
                        pixel_left: currentLeft,
                        pixel_top: currentTop,
                        pixel_width: currentWidth,
                        pixel_height: currentHeight,
                        
                        // PDF coordinates (converted from pixels)
                        pdf_x: pdfLeft,
                        pdf_y: pdfTop,
                        pdf_width: pdfWidth,
                        pdf_height: pdfHeight,
                        
                        // Scale factors
                        scale_x: scaleX,
                        scale_y: scaleY,
                        viewport_width: effectiveWidth,
                        viewport_height: effectiveHeight,
                        
                        // Bounding boxes for compatibility
                        original_bbox: [originalWord.left, originalWord.top, originalWord.left + originalWord.width, originalWord.top + originalWord.height],
                        bbox: [pdfLeft, pdfTop, pdfLeft + pdfWidth, pdfTop + pdfHeight],
                        
                        // Text baseline anchor point
                        origin_x: pdfOriginX,
                        origin_y: pdfOriginY,
                        
                        // Font information
                        font_xref: field.dataset.fontXref ? parseInt(field.dataset.fontXref, 10) : null,
                        font: originalWord.font,
                        font_size: currentFontSizePdf,
                        color: colorHex,
                        
                        // Page dimensions
                        page_width: pageWidth,
                        page_height: pageHeight
                    };
                    
                    overlayEditedFields.set(key, editData);
                } else {
                    overlayEditedFields.delete(key);
                }
                updateOverlaySaveButton();
                persistOverlayEdits();
            }
            
            function updateOverlaySaveButton() {
                if (!saveOverlayBtn) {
                    return;
                }
                saveOverlayBtn.disabled = overlayEditedFields.size === 0;
                if (overlayEditedFields.size > 0) {
                    saveOverlayBtn.textContent = `Save ${overlayEditedFields.size} Change${overlayEditedFields.size > 1 ? 's' : ''}`;
                } else {
                    saveOverlayBtn.textContent = 'Save Changes';
                }
            }

            function updateUndoRedoButtons() {
                if (overlayUndoBtn) {
                    overlayUndoBtn.disabled = overlayUndoStack.length === 0;
                }
                if (overlayRedoBtn) {
                    overlayRedoBtn.disabled = overlayRedoStack.length === 0;
                }
            }

            function pushUndoState() {
                // Save current state to undo stack
                const state = new Map(overlayEditedFields);
                overlayUndoStack.push(state);
                // Limit stack size to 50 items
                if (overlayUndoStack.length > 50) {
                    overlayUndoStack.shift();
                }
                // Clear redo stack when new action is performed
                overlayRedoStack = [];
                updateUndoRedoButtons();
            }

            function overlayUndo() {
                if (overlayUndoStack.length === 0) return;
                
                // Save current state to redo stack
                const currentState = new Map(overlayEditedFields);
                overlayRedoStack.push(currentState);
                
                // Restore previous state
                const previousState = overlayUndoStack.pop();
                overlayEditedFields = new Map(previousState);
                
                // Re-render overlay
                updateUndoRedoButtons();
                updateOverlaySaveButton();
                persistOverlayEdits();
                renderPdfWithOverlay();
            }

            function overlayRedo() {
                if (overlayRedoStack.length === 0) return;
                
                // Save current state to undo stack
                const currentState = new Map(overlayEditedFields);
                overlayUndoStack.push(currentState);
                
                // Restore next state
                const nextState = overlayRedoStack.pop();
                overlayEditedFields = new Map(nextState);
                
                // Re-render overlay
                updateUndoRedoButtons();
                updateOverlaySaveButton();
                persistOverlayEdits();
                renderPdfWithOverlay();
            }
            
            function persistOverlayEdits() {
                try {
                    const editsArray = Array.from(overlayEditedFields.entries()).map(([key, value]) => ({
                        key,
                        value
                    }));
                    sessionStorage.setItem(overlayEditsStorageKey, JSON.stringify(editsArray));
                } catch (err) {
                    console.warn('Failed to persist overlay edits', err);
                }
            }

            function loadOverlayEdits() {
                overlayEditedFields.clear();
                try {
                    const stored = sessionStorage.getItem(overlayEditsStorageKey);
                    if (!stored) return;
                    const parsed = JSON.parse(stored);
                    if (!Array.isArray(parsed)) return;
                    parsed.forEach((item) => {
                        if (item && item.key && item.value) {
                            overlayEditedFields.set(item.key, item.value);
                        }
                    });
                } catch (err) {
                    console.warn('Failed to load overlay edits', err);
                }
            }
            
            // Overlay edits are client-side only for now (no server persistence).

            const updateZoom = (value) => {
                const zoomValue = Math.max(50, Math.min(200, parseInt(value, 10)));
                zoomLabel.textContent = zoomValue + '%';
                currentScale = baseScale * (zoomValue / 100);
                if (basePdfUrl === cleanPdfUrl && overlayExtractionData) {
                    renderPdfWithOverlay(true);
                } else {
                    rerenderPdf();
                }
                updateSelectionBar();
            };

            const scrollToPage = (pageNumber) => {
                const clamped = Math.max(1, Math.min(totalPages || 1, pageNumber));
                if (pageJumpInput) {
                    pageJumpInput.value = clamped;
                }
                const target = viewer.querySelector(
                    `.page[data-page-index="${clamped - 1}"], .overlay-page[data-page-number="${clamped}"]`
                );
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            };

            const updatePageControls = () => {
                if (pageTotalLabel) {
                    pageTotalLabel.textContent = String(totalPages || 1);
                }
                if (pageJumpInput) {
                    pageJumpInput.max = String(totalPages || 1);
                    if (!pageJumpInput.value || parseInt(pageJumpInput.value, 10) > (totalPages || 1)) {
                        pageJumpInput.value = '1';
                    }
                }
            };

            if (zoomOutBtn) {
                zoomOutBtn.addEventListener('click', () => updateZoom(Math.round((currentScale / baseScale) * 100) - 10));
            }
            if (zoomInBtn) {
                zoomInBtn.addEventListener('click', () => updateZoom(Math.round((currentScale / baseScale) * 100) + 10));
            }
            if (pageJumpInput) {
                pageJumpInput.addEventListener('change', () => {
                    const value = parseInt(pageJumpInput.value || '1', 10);
                    if (Number.isFinite(value)) {
                        scrollToPage(value);
                    }
                });
                pageJumpInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        const value = parseInt(pageJumpInput.value || '1', 10);
                        if (Number.isFinite(value)) {
                            scrollToPage(value);
                        }
                    }
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

            loadAnnotationsFromStorage();
            updateAnnotationsList(); // Populate annotations panel with loaded annotations
            loadOriginalPdfBytes().catch((err) => {
                console.warn('Failed to cache original PDF bytes', err);
            });
            updateOverlayShowOriginalToggle();
            updateModeButtons();
            updateSelectionBar();
            
            renderPdf().catch(() => {
                setStatus('Failed to load PDF.', 'err');
            });

            // Tab switching
            const tabButtons = document.querySelectorAll('.tab-btn');
            const tabContents = document.querySelectorAll('.tab-content');
            let extractionDataLoaded = false;
            let extractionData = null;
            const modifiedTexts = new Map();
            
            tabButtons.forEach(button => {
                button.addEventListener('click', () => {
                    const tabId = button.dataset.tab;
                    
                    // Update active tab button
                    tabButtons.forEach(btn => btn.classList.remove('active'));
                    button.classList.add('active');
                    
                    // Update active tab content
                    tabContents.forEach(content => content.classList.remove('active'));
                    document.getElementById(tabId).classList.add('active');
                    
                    // Load extraction data when extracted text tab is clicked
                    if (tabId === 'extracted-text' && !extractionDataLoaded) {
                        loadExtractedText();
                    }
                });
            });

            async function loadExtractedText() {
                const container = document.getElementById('extracted-text-view');
                
                try {
                    const response = await fetch(`/documents/{{ $document->id }}/fitz-extraction-data`);
                    const data = await response.json();
                    
                    if (!data.extraction_data) {
                        container.innerHTML = '<div class="extracted-loading">No extraction data found. Please wait for processing to complete.</div>';
                        return;
                    }
                    
                    extractionData = data.extraction_data;
                    extractionDataLoaded = true;
                    renderExtractedText();
                } catch (error) {
                    console.error('Failed to load extraction:', error);
                    container.innerHTML = '<div class="extracted-loading">Failed to load extraction data. ' + error.message + '</div>';
                }
            }

            function renderExtractedText() {
                const container = document.getElementById('extracted-text-view');
                container.innerHTML = '';

                extractionData.forEach((pageData, pageIndex) => {
                    const pageDiv = document.createElement('div');
                    pageDiv.className = 'extraction-page';
                    pageDiv.style.width = pageData.width + 'px';
                    pageDiv.style.height = pageData.height + 'px';

                    const pageHeader = document.createElement('div');
                    pageHeader.className = 'extraction-page-header';
                    pageHeader.textContent = `Page ${pageData.page_number} of ${extractionData.length}`;
                    pageDiv.appendChild(pageHeader);

                    pageData.words.forEach((word, wordIndex) => {
                        const span = document.createElement('span');
                        span.className = 'text-span';
                        span.contentEditable = 'true';
                        span.textContent = word.text;
                        span.style.left = word.left + 'px';
                        span.style.top = word.top + 'px';
                        span.style.fontSize = word.font_size + 'px';
                        span.style.fontFamily = word.font || 'serif';
                        span.style.fontWeight = word.bold ? 'bold' : 'normal';
                        span.style.fontStyle = word.italic ? 'italic' : 'normal';
                        
                        const wordKey = `${pageIndex}-${wordIndex}`;
                        
                        span.addEventListener('focus', () => {
                            span.classList.add('editing');
                        });
                        
                        span.addEventListener('blur', () => {
                            span.classList.remove('editing');
                            const newText = span.textContent;
                            if (newText !== word.text) {
                                span.classList.add('modified');
                                modifiedTexts.set(wordKey, {
                                    pageIndex,
                                    wordIndex,
                                    originalText: word.text,
                                    newText,
                                    ...word
                                });
                            } else if (newText === word.text && span.classList.contains('modified')) {
                                span.classList.remove('modified');
                                modifiedTexts.delete(wordKey);
                            }
                        });
                        
                        span.addEventListener('keydown', (e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                span.blur();
                            }
                        });

                        pageDiv.appendChild(span);
                    });

                    container.appendChild(pageDiv);
                });
            }
        </script>
        
        <!-- Bootstrap 5.3.3 JS Bundle -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    </body>
</html>
