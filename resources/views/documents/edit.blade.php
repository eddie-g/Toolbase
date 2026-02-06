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
            /* Light theme overrides for Tailwind classes */
            body.light-theme header,
            body.light-theme aside,
            body.light-theme .sticky {
                background: rgba(255, 255, 255, 0.98) !important;
                border-color: rgba(0, 0, 0, 0.1) !important;
            }
            /* Keep top nav bar styled consistently */
            body.light-theme nav.sticky {
                background: rgba(255, 255, 255, 0.95) !important;
                border-color: rgba(0, 0, 0, 0.1) !important;
            }
            body.light-theme nav.sticky .text-white {
                color: #1a202c !important;
            }
            body.light-theme nav.sticky a.bg-blue-600 {
                color: white !important;
            }
            body.light-theme nav.sticky .text-blue-500 {
                color: #2563eb !important;
            }
            /* Tab navigation styling */
            body.light-theme #tab-nav {
                background: rgba(255, 255, 255, 0.98) !important;
                border-color: rgba(0, 0, 0, 0.1) !important;
            }
            body.light-theme #tab-nav .tab-btn {
                background: transparent !important;
                color: #6b7280 !important;
                border-bottom-color: transparent !important;
            }
            body.light-theme #tab-nav .tab-btn:hover {
                color: #1a202c !important;
                background: transparent !important;
            }
            body.light-theme #tab-nav .tab-btn.active,
            body.light-theme #tab-nav .tab-btn.text-white {
                color: #1a202c !important;
                border-bottom-color: #2563eb !important;
                background: transparent !important;
            }
            /* Keep login button blue */
            body.light-theme a.bg-blue-600 {
                background: #2563eb !important;
                color: white !important;
            }
            /* Keep return button orange */
            body.light-theme a.bg-orange-500 {
                background: #f97316 !important;
                color: white !important;
            }
            body.light-theme a.bg-orange-500:hover {
                background: #ea580c !important;
            }
            /* PDF title in light theme */
            body.light-theme #pdf_title {
                color: #000000 !important;
            }
            body.light-theme a.bg-blue-600:hover {
                background: #1d4ed8 !important;
            }
            body.light-theme .viewer {
                background: #f3f4f6 !important;
            }
            body.light-theme button,
            body.light-theme .tab-btn {
                background: #e5e7eb !important;
                color: #1a202c !important;
            }
            body.light-theme button:hover {
                background: #d1d5db !important;
            }
            body.light-theme .tab-btn.active {
                background: rgba(255, 255, 255, 0.95) !important;
                border-color: #10b981 !important;
                color: #10b981 !important;
            }
            body.light-theme .bg-emerald-500 {
                background: #10b981 !important;
                color: white !important;
            }
            body.light-theme .bg-emerald-500:hover {
                background: #059669 !important;
            }
            body.light-theme .border-gray-600,
            body.light-theme .border-gray-700 {
                border-color: rgba(0, 0, 0, 0.1) !important;
            }
            body.light-theme .text-gray-100,
            body.light-theme .text-gray-200,
            body.light-theme .text-gray-300 {
                color: #1a202c !important;
            }
            body.light-theme .text-gray-400 {
                color: #6b7280 !important;
            }
            body.light-theme svg {
                stroke: currentColor !important;
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
            body.light-theme .group:hover .bg-gray-800\/95 {
                background: rgba(255, 255, 255, 0.98) !important;
                border: 1px solid rgba(0, 0, 0, 0.1);
            }
            body.light-theme .add-page-btn {
                background: #10b981 !important;
            }
            body.light-theme .add-page-btn:hover {
                background: #059669 !important;
            }
            body.light-theme .rotate-page-btn {
                background: #3b82f6 !important;
            }
            body.light-theme .rotate-page-btn:hover {
                background: #2563eb !important;
            }
            body.light-theme .delete-page-btn {
                background: #ef4444 !important;
            }
            body.light-theme .delete-page-btn:hover {
                background: #dc2626 !important;
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
                z-index: 9999;
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
            #extracted-text .layout {
                display: flex;
                min-height: 100vh;
            }
            #extracted-text .viewer-wrap {
                margin-left: 0;
                flex: 1;
            }
            .extracted-text-view {
                background: white;
                height: calc(100vh - 114px);
                overflow: auto;
                padding: 40px;
            }
            
            /* AI Chat Sidebar */
            .ai-chat-sidebar {
                width: 400px;
                background: #1f2937;
                border-right: 1px solid #374151;
                display: flex;
                flex-direction: column;
                height: calc(100vh - 114px);
            }
            .ai-chat-header {
                padding: 16px 20px;
                border-bottom: 1px solid #374151;
                font-weight: 600;
                color: white;
                font-size: 16px;
            }
            .ai-chat-messages {
                flex: 1;
                overflow-y: auto;
                padding: 20px;
                display: flex;
                flex-direction: column;
                gap: 16px;
            }
            .ai-chat-message {
                display: flex;
                flex-direction: column;
                gap: 8px;
                animation: slideIn 0.3s ease-out;
            }
            @keyframes slideIn {
                from {
                    opacity: 0;
                    transform: translateY(10px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            .ai-chat-message.user {
                align-items: flex-end;
            }
            .ai-chat-message.bot {
                align-items: flex-start;
            }
            .ai-message-bubble {
                max-width: 85%;
                padding: 12px 16px;
                border-radius: 12px;
                word-wrap: break-word;
                line-height: 1.5;
            }
            .ai-chat-message.user .ai-message-bubble {
                background: #3b82f6;
                color: white;
                border-bottom-right-radius: 4px;
            }
            .ai-chat-message.bot .ai-message-bubble {
                background: #dbeafe;
                color: #1e40af;
                border-bottom-left-radius: 4px;
            }
            .ai-message-copy {
                display: flex;
                align-items: center;
                gap: 6px;
                font-size: 13px;
                color: #9ca3af;
                cursor: pointer;
                padding: 4px 8px;
                border-radius: 4px;
                transition: all 0.2s;
            }
            .ai-message-copy:hover {
                color: #6b7280;
                background: #374151;
            }
            .ai-chat-input-area {
                padding: 16px 20px;
                border-top: 1px solid #374151;
            }
            .ai-chat-input-wrapper {
                display: flex;
                gap: 12px;
                align-items: flex-end;
            }
            .ai-chat-input-container {
                flex: 1;
                display: flex;
                flex-direction: column;
                gap: 8px;
            }
            .ai-chat-textarea {
                width: 100%;
                background: #374151;
                border: 1px solid #4b5563;
                border-radius: 8px;
                padding: 12px;
                color: white;
                resize: none;
                font-size: 14px;
                min-height: 44px;
                max-height: 120px;
                outline: none;
                transition: border-color 0.2s;
            }
            .ai-chat-textarea:focus {
                border-color: #3b82f6;
            }
            .ai-chat-textarea::placeholder {
                color: #9ca3af;
            }
            .ai-attach-btn {
                display: flex;
                align-items: center;
                gap: 6px;
                padding: 8px 12px;
                background: transparent;
                border: none;
                color: #9ca3af;
                cursor: pointer;
                border-radius: 6px;
                font-size: 14px;
                transition: all 0.2s;
            }
            .ai-attach-btn:hover {
                color: #d1d5db;
                background: #374151;
            }
            .ai-send-btn {
                width: 40px;
                height: 40px;
                background: #1e293b;
                border: none;
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                transition: all 0.2s;
                flex-shrink: 0;
            }
            .ai-send-btn:hover {
                background: #334155;
            }
            .ai-send-btn:active {
                transform: scale(0.95);
            }
            .ai-send-btn svg {
                width: 20px;
                height: 20px;
                color: white;
            }
            
            /* Request History Panel */
            .ai-request-history-panel {
                border-top: 1px solid rgba(255,255,255,0.1);
                background: rgba(0,0,0,0.2);
            }
            .history-header {
                padding: 12px 16px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                cursor: pointer;
                transition: background 0.2s;
                font-weight: 600;
                font-size: 14px;
                color: #e5e7eb;
            }
            .history-header:hover {
                background: rgba(255,255,255,0.05);
            }
            .history-toggle {
                background: none;
                border: none;
                padding: 4px;
                cursor: pointer;
                color: #9ca3af;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: transform 0.2s;
            }
            .history-toggle.open {
                transform: rotate(180deg);
            }
            .history-content {
                max-height: 300px;
                overflow-y: auto;
            }
            .history-list {
                padding: 8px;
            }
            .history-item {
                background: rgba(255,255,255,0.05);
                border: 1px solid rgba(255,255,255,0.1);
                border-radius: 6px;
                padding: 12px;
                margin-bottom: 8px;
                font-size: 12px;
            }
            .history-item-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 8px;
            }
            .history-item-cost {
                font-weight: 700;
                color: #60a5fa;
                font-size: 14px;
            }
            .history-item-date {
                color: #9ca3af;
                font-size: 11px;
            }
            .history-item-prompt {
                color: #d1d5db;
                margin-bottom: 8px;
                line-height: 1.4;
            }
            .history-item-stats {
                display: flex;
                gap: 12px;
                padding-top: 8px;
                border-top: 1px solid rgba(255,255,255,0.1);
                color: #9ca3af;
            }
            .history-item-stat {
                display: flex;
                align-items: center;
                gap: 4px;
            }
            
            /* Light theme support for chat */
            body.light-theme .ai-chat-sidebar {
                background: #f9fafb;
                border-right: 1px solid #e5e7eb;
            }
            body.light-theme .ai-chat-header {
                color: #111827;
                border-bottom: 1px solid #e5e7eb;
            }
            body.light-theme .ai-chat-message.bot .ai-message-bubble {
                background: #dbeafe;
                color: #1e40af;
            }
            body.light-theme .ai-chat-textarea {
                background: white;
                border: 1px solid #d1d5db;
                color: #111827;
            }
            body.light-theme .ai-chat-textarea:focus {
                border-color: #3b82f6;
            }
            body.light-theme .ai-chat-input-area {
                border-top: 1px solid #e5e7eb;
            }
            body.light-theme .ai-send-btn {
                background: #1e293b;
            }
            body.light-theme .ai-send-btn:hover {
                background: #334155;
            }
            body.light-theme .ai-message-copy:hover {
                background: #f3f4f6;
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
                overflow: visible;
                flex: 1;
            }
            #ai-viewer {
                overflow: visible;
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
                background: rgba(17, 24, 39, 0.97);
                border-bottom: 1px solid rgba(255,255,255,0.08);
                padding: 0 12px;
                height: 44px;
                color: #e5e7eb;
                font-size: 13px;
                backdrop-filter: blur(12px);
            }
            .edit-text-banner.visible {
                display: flex;
                align-items: center;
                gap: 6px;
            }
            .edit-text-banner .etb-divider {
                width: 1px;
                height: 24px;
                background: rgba(255,255,255,0.12);
                margin: 0 4px;
                flex-shrink: 0;
            }
            .edit-text-banner select,
            .edit-text-banner input[type="number"] {
                background: rgba(255,255,255,0.07);
                border: 1px solid rgba(255,255,255,0.12);
                color: #e5e7eb;
                border-radius: 6px;
                padding: 4px 8px;
                font-size: 12px;
                height: 32px;
                outline: none;
                transition: border-color 0.15s;
            }
            .edit-text-banner select:focus,
            .edit-text-banner input[type="number"]:focus {
                border-color: rgba(110, 231, 183, 0.5);
            }
            .edit-text-banner select {
                cursor: pointer;
                -webkit-appearance: none;
                appearance: none;
                padding-right: 24px;
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%239ca3af'/%3E%3C/svg%3E");
                background-repeat: no-repeat;
                background-position: right 8px center;
            }
            .edit-text-banner .etb-font-select { width: 130px; }
            .edit-text-banner .etb-size-input {
                width: 56px;
                text-align: center;
                -moz-appearance: textfield;
            }
            .edit-text-banner .etb-size-input::-webkit-inner-spin-button,
            .edit-text-banner .etb-size-input::-webkit-outer-spin-button {
                -webkit-appearance: none;
                margin: 0;
            }
            .edit-text-banner .etb-btn {
                width: 32px;
                height: 32px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: rgba(255,255,255,0.07);
                border: 1px solid rgba(255,255,255,0.12);
                color: #9ca3af;
                border-radius: 6px;
                cursor: pointer;
                font-size: 14px;
                transition: all 0.15s;
                flex-shrink: 0;
            }
            .edit-text-banner .etb-btn:hover {
                background: rgba(255,255,255,0.12);
                color: #e5e7eb;
            }
            .edit-text-banner .etb-btn.active {
                background: rgba(110, 231, 183, 0.15);
                border-color: rgba(110, 231, 183, 0.4);
                color: #6ee7b7;
            }
            .edit-text-banner .etb-color-wrap {
                position: relative;
                width: 32px;
                height: 32px;
                border-radius: 6px;
                overflow: hidden;
                border: 1px solid rgba(255,255,255,0.12);
                flex-shrink: 0;
                cursor: pointer;
            }
            .edit-text-banner .etb-color-wrap input[type="color"] {
                position: absolute;
                width: 200%;
                height: 200%;
                top: -50%;
                left: -50%;
                border: none;
                cursor: pointer;
                padding: 0;
            }
            .edit-text-banner .etb-color-swatch {
                width: 100%;
                height: 100%;
                display: block;
                pointer-events: none;
            }
            .edit-text-banner .etb-opacity-group {
                display: flex;
                align-items: center;
                gap: 4px;
            }
            .edit-text-banner .etb-opacity-group svg {
                color: #9ca3af;
                flex-shrink: 0;
            }
            .edit-text-banner .etb-opacity-select {
                width: 64px;
            }
            .edit-text-banner .etb-label {
                font-size: 11px;
                color: #6b7280;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                flex-shrink: 0;
            }
            /* Text box creation (Add Text mode) */
            .text-box-creator {
                position: absolute;
                border: 2px dashed rgba(110, 231, 183, 0.7);
                background: rgba(110, 231, 183, 0.04);
                border-radius: 4px;
                z-index: 100;
                min-width: 140px;
                min-height: 36px;
                cursor: text;
            }
            .text-box-creator:focus-within {
                border-color: rgba(110, 231, 183, 0.9);
                background: rgba(255,255,255,0.95);
            }
            .text-box-creator .tbc-input {
                width: 100%;
                min-height: 28px;
                background: transparent;
                border: none;
                outline: none;
                color: #111;
                font-size: 16px;
                padding: 4px 8px;
                resize: none;
                font-family: inherit;
                box-sizing: border-box;
            }
            .text-box-creator .tbc-menu {
                position: absolute;
                bottom: calc(100% + 6px);
                left: 50%;
                transform: translateX(-50%);
                background: rgba(17, 24, 39, 0.95);
                border: 1px solid rgba(255,255,255,0.15);
                border-radius: 8px;
                padding: 4px;
                display: flex;
                align-items: center;
                gap: 2px;
                box-shadow: 0 4px 16px rgba(0,0,0,0.4);
                z-index: 101;
                white-space: nowrap;
                backdrop-filter: blur(8px);
            }
            .text-box-creator .tbc-menu-btn {
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
            .text-box-creator .tbc-menu-btn:hover {
                background: rgba(255,255,255,0.1);
                color: #e5e7eb;
                border-color: rgba(255,255,255,0.12);
            }
            .text-box-creator .tbc-menu-btn.tbc-ok {
                color: #6ee7b7;
            }
            .text-box-creator .tbc-menu-btn.tbc-ok:hover {
                background: rgba(110, 231, 183, 0.15);
                border-color: rgba(110, 231, 183, 0.3);
            }
            .text-box-creator .tbc-menu-btn.tbc-delete {
                color: #f87171;
            }
            .text-box-creator .tbc-menu-btn.tbc-delete:hover {
                background: rgba(248, 113, 113, 0.15);
                border-color: rgba(248, 113, 113, 0.3);
            }
            .text-box-creator .tbc-menu-divider {
                width: 1px;
                height: 20px;
                background: rgba(255,255,255,0.12);
                margin: 0 2px;
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
                background: rgba(66, 133, 244, 0.8);
                z-index: 10;
            }

            /* Shape type buttons (modern grid) */
            .shape-type-btn {
                width: 100%;
                aspect-ratio: 1;
                border: 2px solid #e5e7eb;
                background: #fff;
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                transition: all 0.15s;
                color: #374151;
            }
            .shape-type-btn:hover {
                border-color: #93c5fd;
                background: #eff6ff;
                color: #2563eb;
            }
            .shape-type-btn.active {
                border-color: #3b82f6;
                background: #eff6ff;
                color: #2563eb;
                box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
            }

            /* Toggle slider for gridlines */
            .grid-toggle-slider {
                position: absolute;
                inset: 0;
                background: #d1d5db;
                border-radius: 24px;
                transition: background 0.25s;
            }
            .grid-toggle-slider::before {
                content: '';
                position: absolute;
                width: 18px;
                height: 18px;
                left: 3px;
                bottom: 3px;
                background: #fff;
                border-radius: 50%;
                transition: transform 0.25s;
                box-shadow: 0 1px 3px rgba(0,0,0,0.2);
            }
            #settings-gridlines-toggle:checked + .grid-toggle-slider {
                background: #3b82f6;
            }
            #settings-gridlines-toggle:checked + .grid-toggle-slider::before {
                transform: translateX(20px);
            }

            /* Gridlines overlay */
            .settings-popover {
                position: absolute;
                top: calc(100% + 8px);
                right: 0;
                background: #1f2937;
                border: 1px solid rgba(255,255,255,0.15);
                border-radius: 12px;
                box-shadow: 0 8px 32px rgba(0,0,0,0.4);
                z-index: 100;
            }
            .settings-popover::before {
                content: '';
                position: absolute;
                top: -6px;
                right: 12px;
                width: 12px;
                height: 12px;
                background: #1f2937;
                border-left: 1px solid rgba(255,255,255,0.15);
                border-top: 1px solid rgba(255,255,255,0.15);
                transform: rotate(45deg);
            }
            .gridlines-overlay {
                position: absolute;
                inset: 0;
                pointer-events: none;
                z-index: 5;
            }
            .overlay-field .resize-handle.corner {
                width: 8px;
                height: 8px;
                border-radius: 50%;
            }
            .overlay-field .resize-handle.edge {
                background: rgba(66, 133, 244, 0.5);
            }
            .overlay-field .box-menu {
                position: absolute;
                top: -38px;
                right: -1px;
                display: none;
                flex-direction: row;
                gap: 0;
                background: #fff;
                border: 1px solid rgba(66, 133, 244, 0.5);
                border-radius: 6px;
                padding: 2px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.18);
                z-index: 20;
                pointer-events: auto;
            }
            .overlay-field.active .box-menu {
                display: flex;
            }
            .overlay-field .box-menu button {
                display: flex;
                align-items: center;
                gap: 4px;
                padding: 4px 10px;
                border: none;
                background: transparent;
                cursor: pointer;
                font-size: 12px;
                font-weight: 500;
                border-radius: 4px;
                color: #444;
                white-space: nowrap;
                line-height: 1;
                transition: background 0.15s, color 0.15s;
            }
            .overlay-field .box-menu button:hover {
                background: rgba(66, 133, 244, 0.1);
            }
            .overlay-field .box-menu .menu-drag {
                cursor: grab;
            }
            .overlay-field .box-menu .menu-drag:active {
                cursor: grabbing;
            }
            .overlay-field .box-menu .menu-delete:hover {
                background: rgba(255, 107, 107, 0.15);
                color: #d32f2f;
            }
            .overlay-field .box-menu .menu-split:hover {
                background: rgba(156, 39, 176, 0.12);
                color: #7b1fa2;
            }
            .overlay-field .box-menu .menu-divider {
                width: 1px;
                background: rgba(0,0,0,0.12);
                margin: 2px 0;
                padding: 0;
            }
            .overlay-field .resize-handle.n { top: -4px; left: 50%; width: 14px; height: 6px; transform: translateX(-50%); cursor: ns-resize; }
            .overlay-field .resize-handle.s { bottom: -4px; left: 50%; width: 14px; height: 6px; transform: translateX(-50%); cursor: ns-resize; }
            .overlay-field .resize-handle.e { right: -4px; top: 50%; width: 6px; height: 14px; transform: translateY(-50%); cursor: ew-resize; }
            .overlay-field .resize-handle.w { left: -4px; top: 50%; width: 6px; height: 14px; transform: translateY(-50%); cursor: ew-resize; }
            .overlay-field .resize-handle.ne { right: -4px; top: -4px; cursor: nesw-resize; }
            .overlay-field .resize-handle.nw { left: -4px; top: -4px; cursor: nwse-resize; }
            .overlay-field .resize-handle.se { right: -4px; bottom: -4px; cursor: nwse-resize; }
            .overlay-field .resize-handle.sw { left: -4px; bottom: -4px; cursor: nesw-resize; }

            /* Section overlay resize handles */
            .section-overlay .resize-handle {
                position: absolute;
                background: #3b82f6;
                opacity: 0;
                transition: opacity 0.2s;
                z-index: 100;
            }

            .section-overlay .resize-handle.corner {
                width: 10px;
                height: 10px;
                border-radius: 50%;
            }

            .section-overlay .resize-handle.edge {
                background: #3b82f6;
            }

            .section-overlay .resize-handle.n { top: -4px; left: 50%; width: 14px; height: 6px; transform: translateX(-50%); cursor: ns-resize; }
            .section-overlay .resize-handle.s { bottom: -4px; left: 50%; width: 14px; height: 6px; transform: translateX(-50%); cursor: ns-resize; }
            .section-overlay .resize-handle.e { right: -4px; top: 50%; width: 6px; height: 14px; transform: translateY(-50%); cursor: ew-resize; }
            .section-overlay .resize-handle.w { left: -4px; top: 50%; width: 6px; height: 14px; transform: translateY(-50%); cursor: ew-resize; }
            .section-overlay .resize-handle.ne { right: -4px; top: -4px; cursor: nesw-resize; }
            .section-overlay .resize-handle.nw { left: -4px; top: -4px; cursor: nwse-resize; }
            .section-overlay .resize-handle.se { right: -4px; bottom: -4px; cursor: nwse-resize; }
            .section-overlay .resize-handle.sw { left: -4px; bottom: -4px; cursor: nesw-resize; }

            .section-overlay:hover .resize-handle {
                opacity: 1;
            }

            .page {
                position: relative;
                margin: 0 auto 24px;
                width: fit-content;
                box-shadow: 0 12px 30px rgba(0,0,0,0.35);
                border-radius: 8px;
                overflow: hidden;
                background: #0f1522;
            }
            .page canvas {
                display: block;
            }
            .overlay-page {
                position: relative;
                margin: 0 auto 24px;
                box-shadow: 0 12px 30px rgba(0,0,0,0.35);
                border-radius: 8px;
                overflow: hidden;
                background: #0f1522;
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
            .viewer.overlay-view-mode .box-menu {
                display: none !important;
            }
            .viewer.overlay-hidden .overlay-field {
                display: none !important;
            }
            .viewer.overlay-hidden .overlay-field .resize-handle,
            .viewer.overlay-hidden .overlay-field .box-menu,
            .overlay-field.selected {
                border-color: rgba(66, 133, 244, 0.8) !important;
                box-shadow: 0 0 0 2px rgba(66, 133, 244, 0.25) !important;
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
                background: transparent;
                border-color: transparent;
                box-shadow: none;
            }
            .annotation:hover {
                background: rgba(255,255,255,0.95);
            }
            /* Floating tbc-menu on selected text annotations */
            .annotation .annotation-tbc-menu {
                position: absolute;
                bottom: calc(100% + 6px);
                left: 50%;
                transform: translateX(-50%);
                background: rgba(17, 24, 39, 0.95);
                border: 1px solid rgba(255,255,255,0.15);
                border-radius: 8px;
                padding: 4px;
                display: none;
                align-items: center;
                gap: 2px;
                box-shadow: 0 4px 16px rgba(0,0,0,0.4);
                z-index: 101;
                white-space: nowrap;
                backdrop-filter: blur(8px);
            }
            .annotation.selected .annotation-tbc-menu {
                display: flex;
            }
            .annotation .annotation-tbc-menu .tbc-menu-btn {
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
            .annotation .annotation-tbc-menu .tbc-menu-btn:hover {
                background: rgba(255,255,255,0.1);
                color: #e5e7eb;
                border-color: rgba(255,255,255,0.12);
            }
            .annotation .annotation-tbc-menu .tbc-menu-btn.tbc-ok { color: #6ee7b7; }
            .annotation .annotation-tbc-menu .tbc-menu-btn.tbc-ok:hover { background: rgba(110,231,183,0.15); border-color: rgba(110,231,183,0.3); }
            .annotation .annotation-tbc-menu .tbc-menu-btn.tbc-delete { color: #f87171; }
            .annotation .annotation-tbc-menu .tbc-menu-btn.tbc-delete:hover { background: rgba(248,113,113,0.15); border-color: rgba(248,113,113,0.3); }
            .annotation .annotation-tbc-menu .tbc-menu-divider {
                width: 1px;
                height: 20px;
                background: rgba(255,255,255,0.12);
                margin: 0 2px;
            }
            /* Text annotation rotate handle */
            .annotation .text-rotate-handle {
                position: absolute;
                width: 26px;
                height: 26px;
                border-radius: 50%;
                background: rgba(59, 130, 246, 0.9);
                border: 2px solid #0b1320;
                cursor: grab;
                display: none;
                align-items: center;
                justify-content: center;
                z-index: 20;
                bottom: -52px;
                left: 50%;
                transform: translateX(-50%);
                transition: background 0.15s;
                user-select: none;
            }
            .annotation.selected .text-rotate-handle {
                display: flex;
            }
            .annotation .text-rotate-handle:hover {
                background: rgba(59, 130, 246, 1);
            }
            .annotation .text-rotate-handle:active {
                cursor: grabbing;
                background: rgba(96, 165, 250, 1);
            }
            /* Text annotation resize handles */
            .annotation .text-resize-handle {
                position: absolute;
                width: 10px;
                height: 10px;
                background: rgba(110, 231, 183, 0.9);
                border: 2px solid #0b1320;
                border-radius: 50%;
                z-index: 15;
                display: none;
                cursor: pointer;
            }
            .annotation.selected .text-resize-handle {
                display: block;
            }
            .annotation .text-resize-handle.tr-e  { right: -6px; top: 50%; transform: translateY(-50%); cursor: ew-resize; }
            .annotation .text-resize-handle.tr-w  { left: -6px; top: 50%; transform: translateY(-50%); cursor: ew-resize; }
            .annotation .text-resize-handle.tr-s  { bottom: -6px; left: 50%; transform: translateX(-50%); cursor: ns-resize; }
            .annotation .text-resize-handle.tr-se { right: -6px; bottom: -6px; cursor: nwse-resize; }
            .annotation .text-resize-handle.tr-sw { left: -6px; bottom: -6px; cursor: nesw-resize; }
            /* When a text annotation has a bounding box, show a thin dashed border on selection */
            .annotation.selected.has-bounds {
                border: 1px dashed rgba(110, 231, 183, 0.5);
            }
            /* Drag selection preview for text mode */
            .text-drag-selection {
                position: absolute;
                border: 2px dashed rgba(110, 231, 183, 0.7);
                background: rgba(110, 231, 183, 0.08);
                border-radius: 4px;
                pointer-events: none;
                z-index: 99;
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
                overflow: auto;
                white-space: pre-wrap;
                word-wrap: break-word;
                box-sizing: border-box;
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
            .modal-actions .primary:not(:disabled) {
                background: #10b981;
                color: white;
                cursor: pointer;
            }
            .modal-actions .ghost {
                background: #ffffff;
                border: 1px solid #e5e7eb;
                color: #111827;
            }
            .template-prefab {
                cursor: pointer;
                border: 2px solid rgba(255,255,255,0.1);
                border-radius: 10px;
                overflow: hidden;
                transition: all 0.2s;
                background: rgba(255,255,255,0.05);
            }
            .template-prefab:hover {
                border-color: #3b82f6;
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(59,130,246,0.2);
            }
            .template-prefab.selected {
                border-color: #10b981;
                background: rgba(16,185,129,0.1);
                box-shadow: 0 0 0 3px rgba(16,185,129,0.2);
            }
            .prefab-preview {
                height: 140px;
                padding: 10px;
                display: flex;
                flex-direction: column;
            }
            .prefab-name {
                padding: 10px;
                text-align: center;
                font-weight: 600;
                font-size: 12px;
                background: rgba(0,0,0,0.3);
            }
            .template-section {
                background: rgba(255,255,255,0.08);
                border: 1px solid rgba(255,255,255,0.1);
                border-radius: 8px;
                padding: 12px 16px;
                margin-bottom: 8px;
                display: flex;
                align-items: center;
                gap: 12px;
            }
            .template-section input,
            .template-section select {
                background: rgba(0,0,0,0.3);
                border: 1px solid rgba(255,255,255,0.15);
                color: var(--ink);
                border-radius: 6px;
                padding: 6px 10px;
            }
            .template-section .section-remove {
                margin-left: auto;
                background: transparent;
                border: none;
                color: #ef4444;
                cursor: pointer;
                font-size: 18px;
                padding: 4px 8px;
            }
            .generated-page {
                position: relative;
                overflow: visible;
            }
            .section-overlay {
                user-select: none;
            }
            .section-overlay:hover {
                background: rgba(59, 130, 246, 0.05);
            }
            .generated-page-menu {
                position: absolute;
                top: 8px;
                left: calc(100% + 12px);
                background: rgba(17, 24, 39, 0.95);
                border: 1px solid rgba(255,255,255,0.1);
                border-radius: 8px;
                padding: 8px;
                display: flex;
                flex-direction: column;
                gap: 8px;
                z-index: 100;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            }
            .generated-page-menu button {
                border: none;
                color: white;
                border-radius: 6px;
                padding: 8px 12px;
                cursor: pointer;
                font-size: 13px;
                font-weight: 600;
                transition: all 0.2s;
                white-space: nowrap;
            }
            .generated-page-menu button:hover {
                transform: translateY(-1px);
            }
            .generated-page-menu .delete-page-btn {
                background: rgba(239, 68, 68, 0.9);
            }
            .generated-page-menu .delete-page-btn:hover {
                background: #ef4444;
            }
            .generated-page-menu .lock-sections-btn {
                background: rgba(59, 130, 246, 0.9);
            }
            .generated-page-menu .lock-sections-btn:hover {
                background: #3b82f6;
            }
            .generated-page-menu .lock-sections-btn.locked {
                background: rgba(251, 146, 60, 0.9);
            }
            .generated-page-menu .lock-sections-btn.locked:hover {
                background: #fb923c;
            }
            .generated-page-menu .add-section-btn {
                background: rgba(16, 185, 129, 0.9);
            }
            .generated-page-menu .add-section-btn:hover {
                background: #10b981;
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
            
            /* Section Blocks Palette */
            .section-block {
                background: rgba(59, 130, 246, 0.1);
                border: 2px solid rgba(59, 130, 246, 0.3);
                border-radius: 8px;
                padding: 12px 16px;
                display: flex;
                align-items: center;
                gap: 10px;
                cursor: grab;
                transition: all 0.2s;
                user-select: none;
            }
            .section-block:hover {
                background: rgba(59, 130, 246, 0.2);
                border-color: rgba(59, 130, 246, 0.5);
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);
            }
            .section-block:active {
                cursor: grabbing;
            }
            .section-block .block-icon {
                font-size: 20px;
            }
            .section-block .block-name {
                font-weight: 600;
                font-size: 14px;
                color: #f3f4f6;
            }
            
            /* Page Builder Canvas */
            #page-builder-canvas {
                transition: border-color 0.2s, box-shadow 0.2s;
            }
            
            /* Dropped Sections on Canvas - Visual page blocks */
            .canvas-section {
                position: absolute;
                border: 2px solid rgba(59, 130, 246, 0.6);
                border-radius: 4px;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: move;
                transition: border-color 0.15s, box-shadow 0.15s;
                overflow: hidden;
                font-size: 11px;
                color: #374151;
                user-select: none;
            }
            .canvas-section:hover {
                border-color: rgba(59, 130, 246, 1);
                box-shadow: 0 0 0 2px rgba(59,130,246,0.2);
            }
            .canvas-section.dragging {
                opacity: 0.5;
                z-index: 100;
            }
            .canvas-section-label {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 2px;
                pointer-events: none;
            }
            .canvas-section-label .icon {
                font-size: 16px;
            }
            .canvas-section-label .name {
                font-size: 9px;
                font-weight: 600;
                color: #374151;
                text-align: center;
                line-height: 1.1;
            }
            .canvas-section .remove-btn {
                position: absolute;
                top: 2px;
                right: 2px;
                width: 16px;
                height: 16px;
                border-radius: 50%;
                background: #ef4444;
                border: none;
                color: white;
                font-size: 10px;
                line-height: 16px;
                text-align: center;
                cursor: pointer;
                opacity: 0;
                transition: opacity 0.15s;
                padding: 0;
                z-index: 5;
            }
            .canvas-section:hover .remove-btn {
                opacity: 1;
            }
            .canvas-section .remove-btn:hover {
                background: #dc2626;
            }
            
            /* Tab styles */
            .signature-tabs {
                display: flex;
                background: rgba(255,255,255,0.05);
                border-bottom: 1px solid rgba(255,255,255,0.1);
                padding: 0 24px;
            }
            .signature-tab {
                background: transparent;
                border: none;
                color: rgba(255,255,255,0.6);
                padding: 12px 20px;
                cursor: pointer;
                font-size: 14px;
                font-weight: 600;
                border-bottom: 2px solid transparent;
                transition: all 0.2s;
            }
            .signature-tab:hover {
                color: rgba(255,255,255,0.8);
            }
            .signature-tab.active {
                color: var(--accent);
                border-bottom-color: var(--accent);
            }
            .signature-panel {
                display: none;
            }
            .signature-panel.active {
                display: block;
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
    <body class="bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 text-gray-100 min-h-screen">
        <!-- Top Navigation Bar -->
        <nav class="bg-gray-900/95 border-b border-gray-700/50 backdrop-blur-sm sticky top-0 z-[60]">
            <div class="px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-14">
                    <!-- Logo -->
                    <a href="/" class="flex items-center gap-3">
                        <svg class="h-8 w-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <span class="text-xl font-bold text-white">Toolbase</span>
                    </a>

                    <!-- Right Side: Theme Toggle & Login -->
                    <div class="flex items-center gap-3">
                        <button id="theme-toggle" type="button" class="p-2 rounded-lg text-gray-300 hover:bg-gray-700/50 transition" title="Toggle theme">
                            <svg id="theme-icon-dark" class="h-5 w-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                            </svg>
                            <svg id="theme-icon-light" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                            </svg>
                        </button>
                        <a href="/admin/login" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">
                            Login
                        </a>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Mobile Header -->
        <header class="bg-gray-800/95 border-b border-gray-700/50 backdrop-blur-sm sticky top-14 z-50">
            <div class="px-4 py-2">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3 flex-1 min-w-0">
                        <button id="mobile-menu-toggle" class="lg:hidden p-2 hover:bg-gray-700/50 rounded-lg">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                            </svg>
                        </button>
                        <div class="flex-1 min-w-0">
                            <div id="pdf_title" class="text-sm font-semibold truncate">{{ $document->original_name }}</div>
                            <div class="flex items-center gap-2 mt-2">
                                <a href="{{ route('documents.index') }}" class="inline-flex items-center gap-2 px-6 py-2 bg-orange-500 hover:bg-orange-600 rounded-lg text-sm font-medium text-white transition">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                                    </svg>
                                    Return
                                </a>
                                <button id="overlay-undo" type="button" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg text-sm font-medium text-white disabled:opacity-50 disabled:cursor-not-allowed transition" title="Undo (Ctrl+Z)" disabled>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path>
                                    </svg>
                                    <span class="hidden sm:inline">Undo</span>
                                </button>
                                <button id="overlay-redo" type="button" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg text-sm font-medium text-white disabled:opacity-50 disabled:cursor-not-allowed transition" title="Redo (Ctrl+Y)" disabled>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 10h-10a8 8 0 00-8 8v2M21 10l-6 6m6-6l-6-6"></path>
                                    </svg>
                                    <span class="hidden sm:inline">Redo</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                    </div>
                </div>
            </div>
        </header>
        
        <!-- Tab Navigation -->
        <nav id="tab-nav" class="bg-gray-800/95 border-b border-gray-700/50 backdrop-blur-sm">
            <div class="flex gap-8 px-4">
                <button class="tab-btn flex items-center gap-2.5 py-3.5 text-base font-medium text-white border-b-2 border-blue-500 -mb-px transition-all duration-200" data-tab="pdf-editor">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Editor
                </button>
                <button class="tab-btn flex items-center gap-2.5 py-3.5 text-base font-medium text-gray-400 border-b-2 border-transparent hover:text-white -mb-px transition-all duration-200" data-tab="extracted-text" id="extracted-text-tab">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                    </svg>
                    AI
                </button>
            </div>
        </nav>
        
        <div class="tab-content active" id="pdf-editor">
        <div class="flex flex-col lg:flex-row min-h-[calc(100vh-196px)]">
            <!-- Mobile Sidebar Backdrop -->
            <div id="sidebar-backdrop" class="fixed inset-0 bg-black/50 z-30 lg:hidden hidden"></div>
            
            <!-- Sidebar - Mobile Drawer / Desktop Sidebar -->
            <aside id="sidebar" class="fixed lg:sticky lg:top-[196px] inset-y-0 left-0 z-40 w-72 lg:w-64 bg-gray-800/95 border-r border-gray-700/50 transform -translate-x-full lg:translate-x-0 transition-transform duration-300 lg:h-[calc(100vh-196px)] overflow-y-auto">
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
                    <div class="mode-bar px-3 py-2 sticky top-[196px] z-30" id="pdf-mode-bar">
                        <div class="flex items-center justify-between gap-2 flex-wrap">
                            <div class="flex items-center gap-2 flex-wrap">
                                <label class="inline-flex items-center gap-2 px-3 py-2 bg-gray-700/50 rounded-lg cursor-pointer hover:bg-gray-700 transition">
                                    <input type="checkbox" id="mode-overlay-toggle" class="w-4 h-4 rounded border-gray-600 text-emerald-500 focus:ring-emerald-500 focus:ring-offset-gray-800" />
                                    <span class="text-sm font-medium hidden sm:inline">Overlay Editor</span>
                                    <span class="text-sm font-medium sm:hidden">Overlay</span>
                                </label>
                                <button id="mode-text" type="button" class="px-3 py-2 bg-gray-700/50 hover:bg-gray-700 rounded-lg text-sm font-medium transition">
                                    <span class="hidden sm:inline">Add Text</span>
                                    <span class="sm:hidden">Text</span>
                                </button>
                                <button id="mode-sign" type="button" class="px-3 py-2 bg-gray-700/50 hover:bg-gray-700 rounded-lg text-sm font-medium transition">Sign</button>
                                <button id="mode-shape" type="button" class="px-3 py-2 bg-gray-700/50 hover:bg-gray-700 rounded-lg text-sm font-medium transition">
                                    <span class="hidden sm:inline">Shapes</span>
                                    <span class="sm:hidden">⬜</span>
                                </button>
                                <button id="view-original-pdf" type="button" class="px-4 py-2 bg-transparent border border-gray-600 hover:bg-gray-700/50 rounded-lg text-sm font-medium transition">
                                    <span class="hidden sm:inline">View Original PDF</span>
                                    <span class="sm:hidden">Original</span>
                                </button>
                            </div>
                            <div class="flex gap-2">
                                <button id="save-btn" class="px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-gray-900 font-semibold rounded-lg text-sm transition" type="button">
                                    <span class="hidden sm:inline">Save PDF</span>
                                    <span class="sm:hidden">Save</span>
                                </button>
                                <button id="save-overlay-btn" class="px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-gray-900 font-semibold rounded-lg text-sm transition hidden" type="button">
                                    <span class="hidden sm:inline">Save Changes</span>
                                    <span class="sm:hidden">Save</span>
                                </button>
                                <button id="clear-btn" class="px-4 py-2 bg-transparent border border-gray-600 hover:bg-gray-700/50 rounded-lg text-sm font-medium transition hidden sm:block" type="button">Clear All</button>
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
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 mt-2" style="display: none;">
                    </div>
                    <div class="selection-toolbar hidden px-3 py-2 bg-gray-700/50" id="selection-toolbar">
                        <div class="toolbar-label text-sm text-gray-400 mb-2" id="selection-label">No text selected</div>
                        <div class="toolbar-controls" id="selection-controls">
                            <select id="selected-font" disabled>
                                <option value="Helvetica">Helvetica</option>
                                <option value="Helvetica-Bold">Helvetica Bold</option>
                                <option value="Times-Roman">Times</option>
                                <option value="Times-Bold">Times Bold</option>
                                <option value="Courier">Courier</option>
                                <option value="Courier-Bold">Courier Bold</option>
                            </select>
                            <select id="selected-weight" disabled>
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
                            <input type="number" id="selected-size" min="8" max="144" value="16" disabled />
                            <button type="button" id="selected-bold" class="icon-btn" title="Bold" disabled><strong>B</strong></button>
                            <button type="button" id="selected-italic" class="icon-btn" title="Italic" disabled><em>I</em></button>
                            <button type="button" id="selected-underline" class="icon-btn" title="Underline" disabled><u>U</u></button>
                            <label class="color-btn" title="Text Color">
                                <span class="color-swatch" id="selected-color-swatch"></span>
                                <input type="color" id="selected-color" value="#111111" disabled />
                            </label>
                            <label class="color-btn" title="Background Color">
                                <span class="color-swatch" id="selected-bg-swatch"></span>
                                <input type="color" id="selected-bg" value="#ffffff" disabled />
                            </label>
                            <select id="selected-align" disabled>
                                <option value="left">Left</option>
                                <option value="center">Center</option>
                                <option value="right">Right</option>
                            </select>
                            <select id="selected-opacity" disabled>
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
                            <button type="button" id="selected-delete" class="danger-btn" title="Delete" disabled>Delete</button>
                        </div>
                    </div>
                </div>

                <div class="edit-text-banner" id="edit-text-banner">
                    <!-- Font Family -->
                    <select class="etb-font-select" id="etb-font" title="Font Family">
                        <option value="Helvetica">Helvetica</option>
                        <option value="TimesRoman">Times Roman</option>
                        <option value="Courier">Courier</option>
                    </select>
                    <div class="etb-divider"></div>
                    <!-- Font Size -->
                    <input type="number" class="etb-size-input" id="etb-size" min="8" max="144" value="16" title="Font Size" />
                    <div class="etb-divider"></div>
                    <!-- Text Color -->
                    <div class="etb-color-wrap" title="Text Color">
                        <input type="color" id="etb-text-color" value="#111111" />
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
                    <button type="button" class="etb-btn" id="etb-bold" title="Bold"><strong>B</strong></button>
                    <!-- Italic -->
                    <button type="button" class="etb-btn" id="etb-italic" title="Italic"><em>I</em></button>
                    <!-- Underline -->
                    <button type="button" class="etb-btn" id="etb-underline" title="Underline" style="text-decoration: underline;">U</button>
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
        <div class="tab-content" id="extracted-text">
        <div class="layout">
            <!-- AI Chat Sidebar -->
            <aside class="ai-chat-sidebar">
                <div class="ai-chat-header">AI Assistant</div>
                <div class="ai-chat-messages" id="ai-chat-messages">
                    <!-- Messages will be dynamically added here -->
                </div>
                
                <!-- Request History Panel -->
                <div class="ai-request-history-panel" id="ai-request-history-panel">
                    <div class="history-header" id="history-header">
                        <span>💰 Request History</span>
                        <button class="history-toggle" id="history-toggle">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>
                    </div>
                    <div class="history-content" id="history-content" style="display: none;">
                        <div class="history-list" id="history-list">
                            <!-- History items will be added here -->
                        </div>
                    </div>
                </div>
                
                <div class="ai-chat-input-area">
                    <div class="ai-chat-input-wrapper">
                        <div class="ai-chat-input-container">
                            <textarea 
                                id="ai-chat-input" 
                                class="ai-chat-textarea" 
                                placeholder="Type your prompt here..."
                                rows="1"
                            ></textarea>
                            <button class="ai-attach-btn" id="ai-attach-btn">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                                </svg>
                                Attach
                            </button>
                        </div>
                        <button class="ai-send-btn" id="ai-send-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 19V5M5 12l7-7 7 7"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </aside>
            
            <main class="viewer-wrap" style="margin-left: 0;">
                <div class="sticky-tools">
                    <div class="mode-bar" id="ai-mode-bar">
                        <button type="button" id="generate-from-template" class="primary">
                            <span class="icon">📄</span>
                            Generate from Template
                        </button>
                        <button type="button" id="customize-prompt-btn" class="ghost">
                            <span class="icon">⚙️</span>
                            Customize Prompt
                        </button>
                        <span class="mode-spacer"></span>
                        <button id="ai-add-to-pdf-btn" class="primary" type="button">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 8px;">
                                <path d="M12 5v14M5 12h14"/>
                            </svg>
                            Add to PDF
                        </button>
                    </div>
                </div>
                <div class="viewer" id="ai-viewer"></div>
            </main>
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

        <!-- Annotations Panel -->
        <div class="annotations-panel collapsed" id="annotations-panel">
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
            <div class="modal-card" style="width: min(520px, 94vw);">
                <div class="modal-header">
                    <span style="font-size: 16px; display: flex; align-items: center; gap: 8px;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
                        Shapes &amp; Tools
                    </span>
                    <button class="modal-close" type="button" id="shape-close">&times;</button>
                </div>
                <div style="padding: 20px; max-height: 70vh; overflow-y: auto;">

                    <!-- Shape Type -->
                    <div style="margin-bottom: 20px;">
                        <div style="font-weight: 600; font-size: 13px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 10px;">Shape</div>
                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;" id="shape-type-grid">
                            <button type="button" class="shape-type-btn active" data-shape="circle" title="Circle">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>
                            </button>
                            <button type="button" class="shape-type-btn" data-shape="triangle" title="Triangle">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3 L21 21 L3 21 Z"/></svg>
                            </button>
                            <button type="button" class="shape-type-btn" data-shape="rect" title="Rectangle">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18"/></svg>
                            </button>
                            <button type="button" class="shape-type-btn" data-shape="x" title="X Mark">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                            <button type="button" class="shape-type-btn" data-shape="checkmark" title="Checkmark">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            </button>
                            <button type="button" class="shape-type-btn" data-shape="star" title="Star">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><polygon points="12,2 15.09,8.26 22,9.27 17,14.14 18.18,21.02 12,17.77 5.82,21.02 7,14.14 2,9.27 8.91,8.26"/></svg>
                            </button>
                            <button type="button" class="shape-type-btn" data-shape="polygon" title="Polygon">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><polygon points="12,2 22,8.5 22,15.5 12,22 2,15.5 2,8.5"/></svg>
                            </button>
                            <button type="button" class="shape-type-btn" data-shape="arrow" title="Arrow">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                            </button>
                        </div>
                    </div>

                    <div style="height: 1px; background: #e5e7eb; margin: 0 -20px 20px;"></div>

                    <!-- Stroke & Fill side by side -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                        <!-- Stroke -->
                        <div>
                            <div style="font-weight: 600; font-size: 13px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 10px;">Stroke</div>
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                                <div style="position: relative; width: 36px; height: 36px; border-radius: 8px; overflow: hidden; border: 2px solid #e5e7eb; flex-shrink: 0;">
                                    <input type="color" id="shape-stroke-color" value="#000000" style="position: absolute; width: 200%; height: 200%; top: -50%; left: -50%; border: none; cursor: pointer;">
                                </div>
                                <input type="text" id="shape-stroke-hex" value="#000000" style="flex: 1; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 7px 10px; color: #111827; font-size: 13px; font-family: monospace;">
                            </div>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px; color: #374151;">
                                <input type="checkbox" id="shape-stroke-transparent" style="width: 16px; height: 16px; accent-color: #3b82f6; cursor: pointer;">
                                Transparent
                            </label>
                        </div>
                        <!-- Fill -->
                        <div>
                            <div style="font-weight: 600; font-size: 13px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 10px;">Fill</div>
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                                <div style="position: relative; width: 36px; height: 36px; border-radius: 8px; overflow: hidden; border: 2px solid #e5e7eb; flex-shrink: 0;">
                                    <input type="color" id="shape-fill-color" value="#000000" style="position: absolute; width: 200%; height: 200%; top: -50%; left: -50%; border: none; cursor: pointer;">
                                </div>
                                <input type="text" id="shape-fill-hex" value="#000000" style="flex: 1; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 7px 10px; color: #111827; font-size: 13px; font-family: monospace;">
                            </div>
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px; color: #374151;">
                                <input type="checkbox" id="shape-fill-transparent" checked style="width: 16px; height: 16px; accent-color: #3b82f6; cursor: pointer;">
                                Transparent
                            </label>
                        </div>
                    </div>

                    <!-- Stroke Width -->
                    <div style="margin-bottom: 16px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <span style="font-weight: 600; font-size: 13px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em;">Stroke Width</span>
                            <span id="shape-stroke-width-label" style="font-size: 13px; font-weight: 600; color: #111827; background: #f3f4f6; padding: 2px 8px; border-radius: 4px;">2px</span>
                        </div>
                        <input type="range" id="shape-stroke-width" min="1" max="20" value="2" style="width: 100%; height: 6px; border-radius: 3px; outline: none; cursor: pointer; accent-color: #3b82f6;">
                    </div>

                    <!-- Opacity -->
                    <div style="margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <span style="font-weight: 600; font-size: 13px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em;">Opacity</span>
                            <span id="shape-opacity-label" style="font-size: 13px; font-weight: 600; color: #111827; background: #f3f4f6; padding: 2px 8px; border-radius: 4px;">100%</span>
                        </div>
                        <input type="range" id="shape-opacity" min="0" max="100" value="100" style="width: 100%; height: 6px; border-radius: 3px; outline: none; cursor: pointer; accent-color: #3b82f6;">
                    </div>

                    <button type="button" id="shape-apply" class="primary" style="width: 100%; padding: 11px 16px; background: #3b82f6; color: #fff; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 14px; transition: background 0.2s;">
                        Apply &amp; Draw Shape
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Generate from Template Modal -->
        <div class="modal" id="generate-from-template-modal" style="display: none;">
            <div class="modal-card" style="max-width: 960px; max-height: 85vh;">
                <div class="modal-header">
                    <span>Generate Page from Template</span>
                    <button class="modal-close" type="button" id="template-modal-close">×</button>
                </div>
                
                <!-- Tabs -->
                <div class="signature-tabs">
                    <button class="signature-tab active" type="button" data-template-tab="prefabs">📋 Prefabs</button>
                    <button class="signature-tab" type="button" data-template-tab="builder">🎨 Custom Builder</button>
                </div>
                
                <!-- Prefabs Tab -->
                <div class="signature-panel active" data-template-panel="prefabs">
                    <div style="padding: 24px; overflow-y: auto; max-height: calc(85vh - 200px);">
                        <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 15px;">Choose a Layout</label>
                        <p style="margin: 0 0 16px; font-size: 12px; color: #6b7280;">Single-page templates with support for columns and images</p>
                        
                        <div id="template-prefabs" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 14px;">
                            <!-- Single Column Layouts -->
                            <div class="template-prefab" data-layout="title-abstract-body">
                                <div class="prefab-preview">
                                    <div style="height: 12%; background: #3b82f6; margin-bottom: 3px; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 8px; color: white; font-weight: 600;">Title</div>
                                    <div style="height: 22%; background: #60a5fa; margin-bottom: 3px; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 7px; color: white;">Abstract</div>
                                    <div style="flex: 1; background: #93c5fd; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 7px; color: white;">Body</div>
                                </div>
                                <div class="prefab-name">Title + Abstract + Body</div>
                            </div>
                            
                            <div class="template-prefab" data-layout="intro-body-conclusion">
                                <div class="prefab-preview">
                                    <div style="height: 20%; background: #8b5cf6; margin-bottom: 3px; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 7px; color: white;">Introduction</div>
                                    <div style="flex: 1; background: #a78bfa; margin-bottom: 3px; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 7px; color: white;">Body</div>
                                    <div style="height: 20%; background: #8b5cf6; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 7px; color: white;">Conclusion</div>
                                </div>
                                <div class="prefab-name">Intro + Body + Conclusion</div>
                            </div>
                            
                            <div class="template-prefab" data-layout="full-body-references">
                                <div class="prefab-preview">
                                    <div style="flex: 1; background: #60a5fa; margin-bottom: 3px; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 7px; color: white;">Body Content</div>
                                    <div style="height: 22%; background: #3b82f6; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 7px; color: white;">References</div>
                                </div>
                                <div class="prefab-name">Body + References</div>
                            </div>
                            
                            <div class="template-prefab" data-layout="full-document">
                                <div class="prefab-preview">
                                    <div style="height: 10%; background: #3b82f6; margin-bottom: 2px; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 6px; color: white; font-weight: 600;">Title</div>
                                    <div style="height: 12%; background: #60a5fa; margin-bottom: 2px; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 6px; color: white;">Abstract</div>
                                    <div style="height: 14%; background: #93c5fd; margin-bottom: 2px; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 6px; color: white;">Intro</div>
                                    <div style="flex: 1; background: #bfdbfe; margin-bottom: 2px; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 6px; color: #3b82f6;">Body</div>
                                    <div style="height: 14%; background: #93c5fd; margin-bottom: 2px; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 6px; color: white;">Conclusion</div>
                                    <div style="height: 10%; background: #3b82f6; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 6px; color: white;">Refs</div>
                                </div>
                                <div class="prefab-name">Full Document</div>
                            </div>
                            
                            <!-- Multi-Column Layouts -->
                            <div class="template-prefab" data-layout="two-column">
                                <div class="prefab-preview">
                                    <div style="height: 12%; background: #3b82f6; margin-bottom: 3px; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 8px; color: white; font-weight: 600;">Title</div>
                                    <div style="flex: 1; display: flex; gap: 3px;">
                                        <div style="flex: 1; background: #10b981; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 6px; color: white;">Left Col</div>
                                        <div style="flex: 1; background: #34d399; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 6px; color: white;">Right Col</div>
                                    </div>
                                </div>
                                <div class="prefab-name">Two Column</div>
                            </div>
                            
                            <div class="template-prefab" data-layout="two-column-image">
                                <div class="prefab-preview">
                                    <div style="height: 12%; background: #3b82f6; margin-bottom: 3px; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 8px; color: white; font-weight: 600;">Title</div>
                                    <div style="flex: 1; display: flex; gap: 3px;">
                                        <div style="flex: 1.2; background: #10b981; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 6px; color: white;">Text</div>
                                        <div style="flex: 0.8; background: linear-gradient(135deg, #f59e0b, #d97706); border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 6px; color: white;">🖼️ Image</div>
                                    </div>
                                </div>
                                <div class="prefab-name">Text + Image</div>
                            </div>
                            
                            <div class="template-prefab" data-layout="three-column">
                                <div class="prefab-preview">
                                    <div style="height: 12%; background: #3b82f6; margin-bottom: 3px; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 8px; color: white; font-weight: 600;">Title</div>
                                    <div style="flex: 1; display: flex; gap: 3px;">
                                        <div style="flex: 1; background: #ec4899; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 5px; color: white;">Col 1</div>
                                        <div style="flex: 1; background: #f472b6; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 5px; color: white;">Col 2</div>
                                        <div style="flex: 1; background: #ec4899; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 5px; color: white;">Col 3</div>
                                    </div>
                                </div>
                                <div class="prefab-name">Three Column</div>
                            </div>
                            
                            <div class="template-prefab" data-layout="methods-results">
                                <div class="prefab-preview">
                                    <div style="flex: 1; background: #6366f1; margin-bottom: 3px; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 7px; color: white;">📊 Methods</div>
                                    <div style="flex: 1; background: #818cf8; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 7px; color: white;">📊 Results</div>
                                </div>
                                <div class="prefab-name">Methods + Results</div>
                            </div>
                            
                            <!-- Image-focused Layouts -->
                            <div class="template-prefab" data-layout="hero-image">
                                <div class="prefab-preview">
                                    <div style="flex: 1; background: linear-gradient(135deg, #f59e0b, #d97706); margin-bottom: 3px; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 8px; color: white;">🖼️ Hero Image</div>
                                    <div style="height: 12%; background: #3b82f6; margin-bottom: 3px; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 7px; color: white; font-weight: 600;">Title</div>
                                    <div style="height: 35%; background: #93c5fd; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 6px; color: white;">Description</div>
                                </div>
                                <div class="prefab-name">Hero Image</div>
                            </div>
                            
                            <div class="template-prefab" data-layout="image-gallery">
                                <div class="prefab-preview">
                                    <div style="height: 12%; background: #3b82f6; margin-bottom: 3px; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 8px; color: white; font-weight: 600;">Title</div>
                                    <div style="flex: 1; display: grid; grid-template-columns: 1fr 1fr; gap: 3px;">
                                        <div style="background: linear-gradient(135deg, #f59e0b, #d97706); border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 6px; color: white;">🖼️</div>
                                        <div style="background: linear-gradient(135deg, #ec4899, #be185d); border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 6px; color: white;">🖼️</div>
                                        <div style="background: linear-gradient(135deg, #10b981, #047857); border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 6px; color: white;">🖼️</div>
                                        <div style="background: linear-gradient(135deg, #6366f1, #4338ca); border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 6px; color: white;">🖼️</div>
                                    </div>
                                </div>
                                <div class="prefab-name">Image Gallery</div>
                            </div>
                            
                            <div class="template-prefab" data-layout="sidebar-layout">
                                <div class="prefab-preview">
                                    <div style="flex: 1; display: flex; gap: 3px;">
                                        <div style="flex: 2; background: #3b82f6; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 6px; color: white;">Main Content</div>
                                        <div style="flex: 1; display: flex; flex-direction: column; gap: 3px;">
                                            <div style="flex: 1; background: linear-gradient(135deg, #f59e0b, #d97706); border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 5px; color: white;">🖼️</div>
                                            <div style="flex: 1.4; background: #93c5fd; border-radius: 2px; display: flex; align-items: center; justify-content: center; font-size: 5px; color: white;">Text</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="prefab-name">Sidebar Layout</div>
                            </div>
                        </div>
                        
                        <div id="template-sections-container" style="display: none; margin-top: 24px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 12px;">Customize Sections</label>
                            <div id="template-sections-list" style="background: rgba(255,255,255,0.05); border-radius: 8px; padding: 16px; min-height: 60px;"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Custom Builder Tab -->
                <div class="signature-panel" data-template-panel="builder">
                    <div style="padding: 24px; overflow-y: auto; max-height: calc(85vh - 200px);">
                        <div style="display: grid; grid-template-columns: 200px 1fr; gap: 24px;">
                            <!-- Section Blocks Palette -->
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px;">Section Blocks</label>
                                <p style="margin: 0 0 12px; font-size: 11px; color: #6b7280;">Drag blocks onto the page preview</p>
                                <div id="section-blocks-palette" style="display: flex; flex-direction: column; gap: 8px;">
                                    <div class="section-block" draggable="true" data-section-type="title">
                                        <span class="block-icon">📄</span>
                                        <span class="block-name">Title</span>
                                    </div>
                                    <div class="section-block" draggable="true" data-section-type="paragraph">
                                        <span class="block-icon">📝</span>
                                        <span class="block-name">Paragraph</span>
                                    </div>
                                    <div class="section-block" draggable="true" data-section-type="chart">
                                        <span class="block-icon">📊</span>
                                        <span class="block-name">Chart</span>
                                    </div>
                                    <div class="section-block" draggable="true" data-section-type="graphic">
                                        <span class="block-icon">🖼️</span>
                                        <span class="block-name">Graphic</span>
                                    </div>
                                </div>
                                
                                <!-- Column presets -->
                                <label style="display: block; font-weight: 600; margin: 20px 0 8px; font-size: 14px;">Quick Columns</label>
                                <div style="display: flex; flex-direction: column; gap: 6px;">
                                    <button class="builder-preset-btn" data-preset="1col" style="padding: 8px 12px; background: rgba(59,130,246,0.1); border: 1px solid rgba(59,130,246,0.3); border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; color: #e5e7eb; transition: all 0.2s; text-align: left;">
                                        <span style="margin-right: 6px;">▐</span> 1 Column
                                    </button>
                                    <button class="builder-preset-btn" data-preset="2col" style="padding: 8px 12px; background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; color: #e5e7eb; transition: all 0.2s; text-align: left;">
                                        <span style="margin-right: 6px;">▐▐</span> 2 Columns
                                    </button>
                                    <button class="builder-preset-btn" data-preset="3col" style="padding: 8px 12px; background: rgba(236,72,153,0.1); border: 1px solid rgba(236,72,153,0.3); border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; color: #e5e7eb; transition: all 0.2s; text-align: left;">
                                        <span style="margin-right: 6px;">▐▐▐</span> 3 Columns
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Visual Page Canvas -->
                            <div>
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                    <label style="font-weight: 600; font-size: 14px;">Page Preview</label>
                                    <button id="builder-clear-btn" style="padding: 4px 10px; background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.3); border-radius: 4px; color: #fca5a5; font-size: 11px; font-weight: 600; cursor: pointer; transition: all 0.2s;">Clear All</button>
                                </div>
                                <div id="page-builder-canvas" style="background: #ffffff; border: 2px solid rgba(59,130,246,0.4); border-radius: 8px; aspect-ratio: 8.5/11; position: relative; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.2);">
                                    <div style="position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #9ca3af; pointer-events: none; font-size: 13px; text-align: center; padding: 20px;" id="builder-empty-state">
                                        Drag section blocks here<br><span style="font-size: 11px; opacity: 0.7;">or use Quick Columns to get started</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button id="template-modal-cancel" class="ghost" type="button">Cancel</button>
                    <button id="template-modal-generate" class="primary" type="button" disabled>Generate Page</button>
                </div>
            </div>
        </div>
        
        <!-- Customize Prompt Modal -->
        <div class="modal" id="customize-prompt-modal" style="display: none;">
            <div class="modal-card" style="max-width: 700px;">
                <div class="modal-header">
                    <span>Customize AI Prompt</span>
                    <button class="modal-close" type="button" id="prompt-modal-close">×</button>
                </div>
                
                <div style="padding: 24px; overflow-y: auto; max-height: calc(80vh - 150px);">
                    <div style="display: flex; flex-direction: column; gap: 20px;">
                        <!-- Style Instruction -->
                        <div>
                            <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #000000;">
                                Style Instruction
                                <span style="font-weight: 400; font-size: 12px; color: #6b7280;">(e.g., "modern and professional", "minimalist", "vibrant and colorful")</span>
                            </label>
                            <input type="text" id="prompt-style" 
                                   placeholder="modern and professional"
                                   style="width: 100%; padding: 10px; border: 1px solid rgba(0,0,0,0.2); border-radius: 6px; background: #ffffff; color: #000000;">
                        </div>
                        
                        <!-- Quality Level -->
                        <div>
                            <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #000000;">
                                Quality Level
                                <span style="font-weight: 400; font-size: 12px; color: #6b7280;">(Image quality descriptor)</span>
                            </label>
                            <input type="text" id="prompt-quality" 
                                   placeholder="high-quality, photorealistic"
                                   style="width: 100%; padding: 10px; border: 1px solid rgba(0,0,0,0.2); border-radius: 6px; background: #ffffff; color: #000000;">
                        </div>
                        
                        <!-- Additional Instructions -->
                        <div>
                            <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #000000;">
                                Additional Instructions
                                <span style="font-weight: 400; font-size: 12px; color: #6b7280;">(Optional: Any specific requirements or details)</span>
                            </label>
                            <textarea id="prompt-additional" 
                                      placeholder="Add any additional instructions for the AI image generation..."
                                      rows="4"
                                      style="width: 100%; padding: 10px; border: 1px solid rgba(0,0,0,0.2); border-radius: 6px; background: #ffffff; color: #000000; resize: vertical; font-family: inherit;"></textarea>
                        </div>
                        
                        <!-- Preview Section -->
                        <div style="background: rgba(59,130,246,0.1); border: 1px solid rgba(59,130,246,0.3); border-radius: 8px; padding: 16px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #60a5fa;">
                                📝 Preview Template
                            </label>
                            <div id="prompt-preview" style="font-size: 13px; line-height: 1.6; color: #d1d5db; font-style: italic;">
                                Generate a professional [type] for a document about: [user prompt]. Section name: [section name]. Image dimensions: [width]px × [height]px. The image should be high-quality, photorealistic, and directly relevant to the content. Style: modern and professional.
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button id="prompt-modal-reset" class="ghost" type="button">Reset to Defaults</button>
                    <button id="prompt-modal-cancel" class="ghost" type="button">Cancel</button>
                    <button id="prompt-modal-save" class="primary" type="button">Save Settings</button>
                </div>
            </div>
        </div>

        <!-- Cost Confirmation Modal -->
        <div class="modal" id="cost-confirmation-modal" style="display: none;">
            <div class="modal-card" style="max-width: 600px;">
                <div class="modal-header">
                    <span>Confirm AI Request</span>
                    <button class="modal-close" type="button" id="cost-modal-close">×</button>
                </div>
                
                <div style="padding: 24px; overflow-y: auto; max-height: calc(80vh - 150px);">
                    <div style="display: flex; flex-direction: column; gap: 20px;">
                        <!-- Cost Summary -->
                        <div style="background: rgba(59,130,246,0.1); border: 1px solid rgba(59,130,246,0.3); border-radius: 8px; padding: 16px;">
                            <h3 style="margin: 0 0 12px 0; color: #60a5fa; font-size: 16px;">💰 Estimated Cost</h3>
                            <div style="font-size: 28px; font-weight: 700; color: #e5e7eb; margin-bottom: 8px;">
                                $<span id="cost-total">0.00</span>
                            </div>
                            <div style="font-size: 13px; color: #9ca3af;">This is an estimate based on prompt length</div>
                        </div>
                        
                        <!-- Cost Breakdown -->
                        <div>
                            <h4 style="margin: 0 0 12px 0; color: #e5e7eb; font-size: 14px;">Cost Breakdown</h4>
                            <div style="display: flex; flex-direction: column; gap: 8px; font-size: 13px;">
                                <div style="display: flex; justify-content: space-between; padding: 8px; background: rgba(255,255,255,0.05); border-radius: 4px;">
                                    <span style="color: #9ca3af;">Text Generation</span>
                                    <span style="color: #e5e7eb; font-weight: 600;">$<span id="cost-text">0.00</span></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding: 8px; background: rgba(255,255,255,0.05); border-radius: 4px;">
                                    <span style="color: #9ca3af;">Image Generation (<span id="cost-image-count">0</span> images)</span>
                                    <span style="color: #e5e7eb; font-weight: 600;">$<span id="cost-images">0.00</span></span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Token Details -->
                        <div>
                            <h4 style="margin: 0 0 12px 0; color: #e5e7eb; font-size: 14px;">Token Details</h4>
                            <div style="display: flex; gap: 16px; font-size: 13px;">
                                <div>
                                    <div style="color: #9ca3af;">Input Tokens</div>
                                    <div style="color: #e5e7eb; font-weight: 600; font-size: 16px;"><span id="cost-input-tokens">0</span></div>
                                </div>
                                <div>
                                    <div style="color: #9ca3af;">Est. Output Tokens</div>
                                    <div style="color: #e5e7eb; font-weight: 600; font-size: 16px;"><span id="cost-output-tokens">0</span></div>
                                </div>
                                <div>
                                    <div style="color: #9ca3af;">Total Tokens</div>
                                    <div style="color: #e5e7eb; font-weight: 600; font-size: 16px;"><span id="cost-total-tokens">0</span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button id="cost-modal-cancel" class="ghost" type="button">Cancel</button>
                    <button id="cost-modal-confirm" class="primary" type="button">Confirm & Generate</button>
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
            const themeIconDark = document.getElementById('theme-icon-dark');
            const themeIconLight = document.getElementById('theme-icon-light');
            const body = document.body;
            
            function updateThemeIcons(isLight) {
                if (isLight) {
                    themeIconDark.classList.remove('hidden');
                    themeIconLight.classList.add('hidden');
                } else {
                    themeIconDark.classList.add('hidden');
                    themeIconLight.classList.remove('hidden');
                }
            }
            
            // Load saved theme preference, default to light theme
            const savedTheme = localStorage.getItem('pdfEditorTheme');
            const isLightTheme = savedTheme ? savedTheme === 'light' : true; // Default to light
            
            if (isLightTheme) {
                body.classList.add('light-theme');
            }
            updateThemeIcons(isLightTheme);
            
            // Set localStorage if not already set
            if (!savedTheme) {
                localStorage.setItem('pdfEditorTheme', 'light');
            }
            
            themeToggle.addEventListener('click', () => {
                body.classList.toggle('light-theme');
                const isLight = body.classList.contains('light-theme');
                updateThemeIcons(isLight);
                localStorage.setItem('pdfEditorTheme', isLight ? 'light' : 'dark');
            });
            
            // Mobile menu toggle
            const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
            const sidebar = document.getElementById('sidebar');
            const sidebarClose = document.getElementById('sidebar-close');
            const sidebarBackdrop = document.getElementById('sidebar-backdrop');
            
            mobileMenuToggle.addEventListener('click', () => {
                const isOpen = !sidebar.classList.contains('-translate-x-full');
                if (isOpen) {
                    closeSidebar();
                } else {
                    sidebar.classList.remove('-translate-x-full');
                    sidebarBackdrop.classList.remove('hidden');
                }
            });
            
            const closeSidebar = () => {
                sidebar.classList.add('-translate-x-full');
                sidebarBackdrop.classList.add('hidden');
            };
            
            sidebarClose.addEventListener('click', closeSidebar);
            sidebarBackdrop.addEventListener('click', closeSidebar);
            
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', (e) => {
                if (window.innerWidth < 1024 && !sidebar.contains(e.target) && !mobileMenuToggle.contains(e.target)) {
                    closeSidebar();
                }
            });
            
            // Handle window resize to adjust PDF scaling
            let resizeTimeout;
            let wasMobile = window.innerWidth < 768;
            window.addEventListener('resize', () => {
                clearTimeout(resizeTimeout);
                resizeTimeout = setTimeout(() => {
                    const nowMobile = window.innerWidth < 768;
                    
                    // If switching between mobile/desktop, update zoom
                    if (wasMobile !== nowMobile) {
                        wasMobile = nowMobile;
                        const newZoom = nowMobile ? 50 : 130;
                        updateZoom(newZoom);
                        return; // updateZoom already re-renders
                    }
                    
                    // Re-render if switching between mobile/desktop breakpoints
                    const currentMode = document.getElementById('mode-overlay-toggle').checked;
                    if (viewer && viewer.children.length > 0) {
                        if (currentMode) {
                            renderPdfWithOverlay(true);
                        } else {
                            rerenderPdf();
                        }
                    }
                }, 300);
            });

            // Only trigger extraction if no extraction data exists yet
            // This prevents overwriting extraction data when page reloads after saving shapes
            // Extraction data should ONLY be updated by overlay editor's save-overlay-btn
            fetch(fitzExtractionDataUrl)
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    // No extraction exists, trigger it
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
                } else {
                    console.log('✓ Extraction data already exists, skipping auto-extraction');
                }
            })
            .catch(err => {
                console.log('Error checking extraction data, will not auto-extract');
            });


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
            const pageRotations = {}; // Track page rotations: {pageIndex: rotation}
            const pendingNewPages = []; // Track pages to add: [{insertAfter: pageIndex, width, height}]
            const pendingDeletedPages = []; // Track pages to delete (0-based indices)
            let draggedPageItem = null;
            let organizePageOrder = [];
            let selectedPageItem = null;
            let originalPdfBytes = null;
            let activeEditor = null;
            let selectedAnnotation = null;
            let selectedOverlayField = null;
            let overlaySelectionRange = null;
            
            // Set initial scale based on screen size
            const isMobile = window.innerWidth < 768;
            let currentScale = isMobile ? 0.5 : 1.3;
            const baseScale = isMobile ? 0.5 : 1.3;
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
                        // Scroll to annotation on page and select it
                        const page = document.querySelector(`[data-page-index="${annotation.pageIndex}"]`);
                        if (page) {
                            page.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                        setSelection(annotation);
                    });
                    
                    // Click on list item to select
                    item.addEventListener('click', (e) => {
                        if (e.target.closest('.annotation-item-actions')) return;
                        const page = document.querySelector(`[data-page-index="${annotation.pageIndex}"]`);
                        if (page) {
                            page.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                        setSelection(annotation);
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
            let gridlinesEnabled = false;
            let gridlinesSpacing = 50;
            let gridlinesColor = '#3b82f6';
            let gridlinesOpacity = 0.15;
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
                updateEditTextBanner();
            }

            function updateTextLayerVisibility() {
                // Keep extracted text hidden in Add Text mode; only show it in Edit Text mode.
                viewer.classList.toggle('text-editing', toolMode === 'edit-text');
                viewer.classList.toggle('edit-text-mode', toolMode === 'edit-text');
            }

            function updateSelectionBar() {
                if (!selectedAnnotation && !selectedOverlayField) {
                    if (selectionLabel) selectionLabel.textContent = 'No text selected';
                    if (selectionControls) selectionControls.classList.add('disabled');
                    if (selectedFont) {
                        selectedFont.value = 'Helvetica';
                        selectedFont.disabled = true;
                    }
                    if (selectedSize) {
                        selectedSize.value = 16;
                        selectedSize.disabled = true;
                    }
                    if (selectedBold) selectedBold.disabled = true;
                    if (selectedItalic) selectedItalic.disabled = true;
                    if (selectedUnderline) selectedUnderline.disabled = true;
                    if (selectedColor) selectedColor.disabled = true;
                    if (selectedBg) selectedBg.disabled = true;
                    if (selectedAlign) selectedAlign.disabled = true;
                    if (selectedOpacity) selectedOpacity.disabled = true;
                    if (selectedDelete) selectedDelete.disabled = true;
                    return;
                }

                if (selectionLabel) selectionLabel.textContent = 'Selected text';
                if (selectionControls) selectionControls.classList.remove('disabled');

                if (selectedAnnotation) {
                    const isText = selectedAnnotation.type === 'text' || !selectedAnnotation.type;
                    if (selectedFont) selectedFont.disabled = !isText;
                    if (selectedWeight) selectedWeight.disabled = !isText;
                    if (selectedSize) selectedSize.disabled = !isText;
                    if (selectedBold) selectedBold.disabled = !isText;
                    if (selectedItalic) selectedItalic.disabled = !isText;
                    if (selectedUnderline) selectedUnderline.disabled = !isText;
                    if (selectedColor) selectedColor.disabled = !isText;
                    if (selectedBg) selectedBg.disabled = !isText;
                    if (selectedAlign) selectedAlign.disabled = !isText;
                    if (selectedOpacity) selectedOpacity.disabled = !isText;
                    if (selectedDelete) selectedDelete.disabled = false;
                    if (isText) {
                        if (selectedFont) selectedFont.value = selectedAnnotation.fontFamily;
                        if (selectedWeight) {
                            const annoWeight = String(selectedAnnotation.fontWeight);
                            selectedWeight.value = ['100','200','300','400','500','600','700','800','900'].includes(annoWeight) ? annoWeight : (annoWeight === 'bold' ? '700' : '400');
                        }
                        if (selectedSize) selectedSize.value = Math.round(selectedAnnotation.fontSize * currentScale);
                        if (selectedColor) selectedColor.value = selectedAnnotation.textColor || '#111111';
                        if (selectedBg) {
                            const background = selectedAnnotation.backgroundColor || 'transparent';
                            selectedBg.value = background === 'transparent' ? '#ffffff' : background;
                        }
                        if (selectedAlign) selectedAlign.value = selectedAnnotation.textAlign || 'left';
                        if (selectedOpacity) {
                            const opacityValue = String(selectedAnnotation.opacity ?? 1);
                            if ([...selectedOpacity.options].some((option) => option.value === opacityValue)) {
                                selectedOpacity.value = opacityValue;
                            } else {
                                selectedOpacity.value = '1';
                            }
                        }
                        if (selectedBold) selectedBold.classList.toggle('active', selectedAnnotation.fontWeight === '700' || selectedAnnotation.fontWeight === 'bold');
                        if (selectedItalic) selectedItalic.classList.toggle('active', selectedAnnotation.fontStyle === 'italic');
                        if (selectedUnderline) selectedUnderline.classList.toggle('active', Boolean(selectedAnnotation.underline));
                    } else {
                        if (selectionLabel) selectionLabel.textContent = 'Selected item';
                        if (selectedFont) selectedFont.value = 'Helvetica';
                        if (selectedSize) selectedSize.value = 16;
                        if (selectedBold) selectedBold.classList.remove('active');
                        if (selectedItalic) selectedItalic.classList.remove('active');
                        if (selectedUnderline) selectedUnderline.classList.remove('active');
                    }
                    return;
                }

                if (selectedOverlayField) {
                    const textEl = getOverlayTextElement(selectedOverlayField);
                    const styles = window.getComputedStyle(textEl);
                    if (selectedFont) selectedFont.disabled = false;
                    if (selectedWeight) selectedWeight.disabled = false;
                    if (selectedSize) selectedSize.disabled = false;
                    if (selectedBold) selectedBold.disabled = false;
                    if (selectedItalic) selectedItalic.disabled = false;
                    if (selectedUnderline) selectedUnderline.disabled = false;
                    if (selectedColor) selectedColor.disabled = false;
                    if (selectedBg) selectedBg.disabled = false;
                    if (selectedAlign) selectedAlign.disabled = false;
                    if (selectedOpacity) selectedOpacity.disabled = false;
                    if (selectedDelete) selectedDelete.disabled = false;

                    if (selectedFont) {
                        const mappedFont = mapFontFamilyToKey(styles.fontFamily);
                        selectedFont.value = mappedFont;
                    }
                    if (selectedWeight) {
                        const weightValue = parseInt(styles.fontWeight, 10);
                        const normalizedWeight = Number.isFinite(weightValue) ? String(Math.round(weightValue / 100) * 100) : '400';
                        selectedWeight.value = ['100','200','300','400','500','600','700','800','900'].includes(normalizedWeight) ? normalizedWeight : '400';
                    }
                    if (selectedSize) selectedSize.value = Math.round(parseFloat(styles.fontSize) || 16);
                    if (selectedColor) selectedColor.value = colorToHex(styles.color) || '#111111';
                    if (selectedBg) {
                        const bgColor = colorToHex(styles.backgroundColor);
                        selectedBg.value = bgColor && bgColor !== 'transparent' ? bgColor : '#ffffff';
                    }
                    if (selectedAlign) selectedAlign.value = styles.textAlign || 'left';
                    if (selectedOpacity) {
                        const opacityValue = String(parseFloat(styles.opacity || '1'));
                        if ([...selectedOpacity.options].some((option) => option.value === opacityValue)) {
                            selectedOpacity.value = opacityValue;
                        } else {
                            selectedOpacity.value = '1';
                        }
                    }
                    if (selectedBold) selectedBold.classList.toggle('active', Number.isFinite(parseInt(styles.fontWeight, 10)) ? parseInt(styles.fontWeight, 10) >= 600 : styles.fontWeight === 'bold');
                    if (selectedItalic) selectedItalic.classList.toggle('active', styles.fontStyle === 'italic' || styles.fontStyle === 'oblique');
                    if (selectedUnderline) {
                        const decoration = styles.textDecorationLine || '';
                        selectedUnderline.classList.toggle('active', decoration.includes('underline'));
                    }
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
                    // Apply bounding box size if present
                    if (annotation.pdfWidth && annotation.pdfHeight) {
                        annotation.element.style.width = (annotation.pdfWidth * currentScale) + 'px';
                        annotation.element.style.height = (annotation.pdfHeight * currentScale) + 'px';
                        annotation.element.style.overflow = 'hidden';
                        annotation.element.style.wordWrap = 'break-word';
                    }
                    // Alignment translateX on the label (no rotation here — rotation goes on textWrapper)
                    const width = annotation.pdfWidth ? (annotation.pdfWidth * currentScale) : measureAnnotationTextWidth(annotation.text, fontSizePx, fontFamily, annotation.fontWeight, annotation.fontStyle);
                    let translateX = 0;
                    if (!annotation.pdfWidth) {
                        if (annotation.textAlign === 'center') {
                            translateX = -width / 2;
                        } else if (annotation.textAlign === 'right') {
                            translateX = -width;
                        }
                    }
                    annotation.element.style.transform = `translateX(${translateX}px)`;
                    // Apply rotation to the text content wrapper only
                    const rot = annotation.rotation || 0;
                    const tw = annotation.textWrapper || annotation.element.querySelector('.text-content-wrapper');
                    if (tw) {
                        tw.style.transform = rot ? `rotate(${rot}deg)` : '';
                        tw.style.transformOrigin = 'center center';
                    }
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
                annotation.rotation = annotation.rotation || 0;
            }

            function addPageThumbnail(pageNumber, canvas) {
                if (!pageList) {
                    return;
                }
                const thumb = document.createElement('div');
                thumb.className = 'relative p-3 bg-gray-700/30 rounded-lg border border-gray-600/50 cursor-pointer transition-all hover:border-emerald-500/50 hover:transform hover:scale-[1.02] group';
                thumb.dataset.pageIndex = String(pageNumber - 1);

                const thumbCanvas = document.createElement('canvas');
                thumbCanvas.className = 'w-full h-auto rounded shadow-lg bg-white';
                const thumbWidth = 240; // Increased from 160 for sharper rendering
                const scale = thumbWidth / canvas.width;
                thumbCanvas.width = Math.round(canvas.width * scale);
                thumbCanvas.height = Math.round(canvas.height * scale);
                const thumbCtx = thumbCanvas.getContext('2d');
                // Enable image smoothing for better quality
                thumbCtx.imageSmoothingEnabled = true;
                thumbCtx.imageSmoothingQuality = 'high';
                thumbCtx.drawImage(canvas, 0, 0, thumbCanvas.width, thumbCanvas.height);

                const label = document.createElement('span');
                label.className = 'block text-center text-sm text-gray-400 mt-2';
                label.textContent = String(pageNumber);

                // Create hover menu
                const hoverMenu = document.createElement('div');
                hoverMenu.id = 'page_controller';
                hoverMenu.className = 'absolute top-1 right-1 transition-opacity flex flex-col gap-1 bg-gray-800/95 rounded-lg shadow-xl p-1 backdrop-blur-sm z-10';
                hoverMenu.style.opacity = '0';
                hoverMenu.style.pointerEvents = 'auto';
                hoverMenu.innerHTML = `
                    <button class="add-page-btn btn btn-success btn-sm shadow-sm d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; padding: 0;" title="Add blank page after">
                        <svg class="text-white" style="width: 16px; height: 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                    </button>
                    <button class="rotate-page-btn btn btn-primary btn-sm shadow-sm d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; padding: 0;" title="Rotate page 90°">
                        <svg class="text-white" style="width: 16px; height: 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                    </button>
                    <div style="height: 8px;"></div>
                    <button class="delete-page-btn btn btn-danger btn-sm shadow-sm d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; padding: 0; border: 2px solid #ff4444;" title="⚠️ Delete page permanently">
                        <svg class="text-white" style="width: 16px; height: 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                    </button>
                `;

                // Show menu on hover
                thumb.addEventListener('mouseenter', () => {
                    hoverMenu.style.opacity = '1';
                });
                thumb.addEventListener('mouseleave', () => {
                    hoverMenu.style.opacity = '0';
                });

                // Prevent menu clicks from triggering page selection
                hoverMenu.addEventListener('click', (e) => {
                    e.stopPropagation();
                });

                // Add page button handler - adds to DOM only, saved on save button
                hoverMenu.querySelector('.add-page-btn').addEventListener('click', async () => {
                    try {
                        // Get dimensions from the reference page
                        const refPageIndex = pageNumber - 1;
                        const refPage = await pdfjsDocument.getPage(refPageIndex + 1);
                        const viewport = refPage.getViewport({ scale: 1.0 });
                        
                        // Calculate pending page index
                        const pendingIdx = pendingNewPages.length;
                        const pendingPageId = 'pending-' + pendingIdx;
                        
                        // Track this pending new page (will be added during save)
                        const pendingPageData = {
                            id: pendingPageId,
                            insertAfter: refPageIndex,
                            width: viewport.width,
                            height: viewport.height,
                            annotations: [] // Annotations specific to this pending page
                        };
                        pendingNewPages.push(pendingPageData);
                        
                        // Create a visual placeholder in the thumbnail list
                        const thumbnailContainer = pageList;
                        if (!thumbnailContainer) {
                            throw new Error('Thumbnail container not found');
                        }
                        const existingThumbnails = thumbnailContainer.querySelectorAll('[data-page-index]');
                        const insertAfterElement = existingThumbnails[refPageIndex];
                        
                        // Calculate new page number (for display)
                        const newPageDisplayNumber = refPageIndex + 2; // +1 for 0-based, +1 for "after"
                        
                        // Create blank thumbnail placeholder
                        const wrapper = document.createElement('div');
                        wrapper.className = 'thumbnail-wrapper pending-page';
                        wrapper.dataset.pageIndex = pendingPageId;
                        wrapper.innerHTML = `
                            <div class="thumbnail-label">New Page</div>
                            <div class="thumbnail-canvas-wrapper" style="background: white; display: flex; align-items: center; justify-content: center; min-height: 150px; border: 2px dashed #28a745;">
                                <span style="color: #28a745; font-size: 24px;">📄</span>
                            </div>
                            <div class="thumbnail-hover-menu">
                                <button class="remove-pending-page-btn btn btn-danger btn-sm shadow-sm d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; padding: 0;" title="Remove pending page">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/><path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/></svg>
                                </button>
                            </div>
                        `;
                        
                        // Insert after the reference page
                        if (insertAfterElement.nextSibling) {
                            thumbnailContainer.insertBefore(wrapper, insertAfterElement.nextSibling);
                        } else {
                            thumbnailContainer.appendChild(wrapper);
                        }
                        
                        // Add remove handler for pending page
                        wrapper.querySelector('.remove-pending-page-btn').addEventListener('click', (e) => {
                            e.stopPropagation();
                            // Find and remove from pendingNewPages array
                            const idx = pendingNewPages.findIndex(p => p.id === pendingPageId);
                            if (idx !== -1) {
                                // Also remove any annotations for this pending page
                                const pageAnnotations = pendingNewPages[idx].annotations || [];
                                for (const ann of pageAnnotations) {
                                    const annIdx = annotations.findIndex(a => a.id === ann.id);
                                    if (annIdx !== -1) annotations.splice(annIdx, 1);
                                }
                                pendingNewPages.splice(idx, 1);
                            }
                            wrapper.remove();
                            // Also remove from main viewer
                            const mainPageEl = viewer.querySelector(`.page[data-page-index="${pendingPageId}"]`);
                            if (mainPageEl) mainPageEl.remove();
                            updateAnnotationsList();
                            setStatus('Pending page removed.', 'ok');
                        });
                        
                        // Also add blank page to main viewer with interactive canvas
                        const scaledViewport = refPage.getViewport({ scale: currentScale });
                        const mainViewer = viewer;
                        const existingPages = mainViewer.querySelectorAll('.page[data-page-index]');
                        const insertAfterMainPage = existingPages[refPageIndex];
                        
                        // Create blank page element for main viewer with proper canvas structure
                        const blankPageEl = document.createElement('div');
                        blankPageEl.className = 'page pending-page';
                        blankPageEl.dataset.pageIndex = pendingPageId;
                        blankPageEl.style.cssText = `
                            position: relative;
                            width: ${scaledViewport.width}px;
                            height: ${scaledViewport.height}px;
                            margin: 10px auto;
                            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
                        `;
                        
                        // Create a real canvas for the blank page
                        const blankCanvas = document.createElement('canvas');
                        blankCanvas.width = scaledViewport.width;
                        blankCanvas.height = scaledViewport.height;
                        blankCanvas.style.cssText = 'position: absolute; top: 0; left: 0; z-index: 1;';
                        const ctx = blankCanvas.getContext('2d');
                        ctx.fillStyle = 'white';
                        ctx.fillRect(0, 0, blankCanvas.width, blankCanvas.height);
                        
                        // Draw a subtle indicator that this is a new page
                        ctx.strokeStyle = '#28a745';
                        ctx.lineWidth = 2;
                        ctx.setLineDash([10, 5]);
                        ctx.strokeRect(10, 10, blankCanvas.width - 20, blankCanvas.height - 20);
                        ctx.setLineDash([]);
                        
                        // Add "New Page" watermark
                        ctx.fillStyle = 'rgba(40, 167, 69, 0.15)';
                        ctx.font = 'bold 48px Arial';
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'middle';
                        ctx.fillText('New Page', blankCanvas.width / 2, blankCanvas.height / 2);
                        
                        blankPageEl.appendChild(blankCanvas);
                        
                        // Create annotation layer for the blank page (same as regular pages)
                        const annotationLayer = document.createElement('div');
                        annotationLayer.className = 'annotation-layer';
                        annotationLayer.style.cssText = `
                            position: absolute;
                            top: 0;
                            left: 0;
                            width: 100%;
                            height: 100%;
                            pointer-events: none;
                            z-index: 5;
                        `;
                        blankPageEl.appendChild(annotationLayer);
                        
                        // Create an overlay for interactions (same as regular pages)
                        const overlay = document.createElement('div');
                        overlay.className = 'overlay pdf-overlay';
                        overlay.style.cssText = `
                            position: absolute;
                            top: 0;
                            left: 0;
                            width: 100%;
                            height: 100%;
                            cursor: crosshair;
                            pointer-events: auto;
                            z-index: 10;
                        `;
                        blankPageEl.appendChild(overlay);
                        
                        // Store canvas reference in pending page data
                        pendingPageData.canvas = blankCanvas;
                        pendingPageData.element = blankPageEl;
                        pendingPageData.scale = currentScale;
                        pendingPageData.overlay = overlay;
                        
                        const pageInfo = {
                            scale: currentScale,
                            canvasHeight: blankCanvas.height,
                        };
                        
                        // Click handler for deselect when not in text mode
                        overlay.addEventListener('click', (event) => {
                            if (event.target !== overlay) return;
                            if (toolMode !== 'text') {
                                setSelection(null);
                                removeActiveEditor();
                            }
                        });
                        
                        // Drag-to-create text box for pending pages
                        (function setupPendingTextDrag(ov, pid, cv, el, pi) {
                            let dragState = null;
                            ov.addEventListener('pointerdown', (e) => {
                                if (toolMode !== 'text') return;
                                if (e.target !== ov) return;
                                e.preventDefault();
                                ov.setPointerCapture(e.pointerId);
                                const rect = ov.getBoundingClientRect();
                                const sx = e.clientX - rect.left;
                                const sy = e.clientY - rect.top;
                                const sel = document.createElement('div');
                                sel.className = 'text-drag-selection';
                                sel.style.left = sx + 'px';
                                sel.style.top = sy + 'px';
                                sel.style.width = '0';
                                sel.style.height = '0';
                                ov.appendChild(sel);
                                dragState = { startX: sx, startY: sy, sel, rect, moved: false };
                            });
                            ov.addEventListener('pointermove', (e) => {
                                if (!dragState) return;
                                const cx = e.clientX - dragState.rect.left;
                                const cy = e.clientY - dragState.rect.top;
                                const lx = Math.min(dragState.startX, cx);
                                const ly = Math.min(dragState.startY, cy);
                                const w = Math.abs(cx - dragState.startX);
                                const h = Math.abs(cy - dragState.startY);
                                dragState.sel.style.left = lx + 'px';
                                dragState.sel.style.top = ly + 'px';
                                dragState.sel.style.width = w + 'px';
                                dragState.sel.style.height = h + 'px';
                                if (w > 5 || h > 5) dragState.moved = true;
                            });
                            ov.addEventListener('pointerup', (e) => {
                                if (!dragState) return;
                                const ds = dragState;
                                dragState = null;
                                const cx = e.clientX - ds.rect.left;
                                const cy = e.clientY - ds.rect.top;
                                const bx = Math.min(ds.startX, cx);
                                const by = Math.min(ds.startY, cy);
                                const bw = Math.abs(cx - ds.startX);
                                const bh = Math.abs(cy - ds.startY);
                                ds.sel.remove();
                                const opts = readBannerOpts();
                                if (ds.moved && bw > 20 && bh > 10) {
                                    createTextBoxCreator(ov, bx, by, pid, cv, el, pi, opts, bw, bh);
                                } else {
                                    createTextBoxCreator(ov, bx, by, pid, cv, el, pi, opts);
                                }
                            });
                        })(overlay, pendingPageId, blankCanvas, blankPageEl, pageInfo);
                        
                        // Add shape drawing handlers for pending page
                        let pendingDrawingShape = null;
                        
                        overlay.addEventListener('pointerdown', (event) => {
                            if (toolMode !== 'shape') return;
                            // Don't start drawing if clicking on an existing annotation
                            if (event.target !== overlay && event.target.closest('.annotation')) {
                                return;
                            }
                            event.preventDefault();
                            overlay.setPointerCapture(event.pointerId);
                            
                            const rect = overlay.getBoundingClientRect();
                            const startX = event.clientX - rect.left;
                            const startY = event.clientY - rect.top;
                            
                            const shapeEl = document.createElement('div');
                            shapeEl.className = 'drawing-shape-preview';
                            shapeEl.style.cssText = `
                                position: absolute;
                                left: ${startX}px;
                                top: ${startY}px;
                                width: 0;
                                height: 0;
                                border: 2px dashed ${shapeStroke};
                                background: ${shapeFillTransparentState ? 'transparent' : shapeFill + '80'};
                                opacity: 0.7;
                                pointer-events: none;
                                z-index: 1000;
                                box-sizing: border-box;
                                ${shapeType === 'circle' || shapeType === 'ellipse' ? 'border-radius: 50%;' : ''}
                            `;
                            overlay.appendChild(shapeEl);
                            
                            pendingDrawingShape = {
                                element: shapeEl,
                                startX,
                                startY
                            };
                        });
                        
                        overlay.addEventListener('pointermove', (event) => {
                            if (!pendingDrawingShape || toolMode !== 'shape') return;
                            
                            const rect = overlay.getBoundingClientRect();
                            const currentX = event.clientX - rect.left;
                            const currentY = event.clientY - rect.top;
                            
                            const left = Math.min(currentX, pendingDrawingShape.startX);
                            const top = Math.min(currentY, pendingDrawingShape.startY);
                            const width = Math.max(1, Math.abs(currentX - pendingDrawingShape.startX));
                            const height = Math.max(1, Math.abs(currentY - pendingDrawingShape.startY));
                            
                            pendingDrawingShape.element.style.left = left + 'px';
                            pendingDrawingShape.element.style.top = top + 'px';
                            pendingDrawingShape.element.style.width = width + 'px';
                            pendingDrawingShape.element.style.height = height + 'px';
                        });
                        
                        overlay.addEventListener('pointerup', (event) => {
                            if (!pendingDrawingShape || toolMode !== 'shape') return;
                            
                            const shapeRect = pendingDrawingShape.element.getBoundingClientRect();
                            const overlayRect = overlay.getBoundingClientRect();
                            const width = shapeRect.width;
                            const height = shapeRect.height;
                            
                            if (width < 6 || height < 6) {
                                pendingDrawingShape.element.remove();
                                pendingDrawingShape = null;
                                return;
                            }
                            
                            const left = shapeRect.left - overlayRect.left;
                            const top = shapeRect.top - overlayRect.top;
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
                                pageIndex: pendingPageId,
                                pdfX,
                                pdfY,
                                pdfWidth,
                                pdfHeight
                            };
                            
                            annotations.push(annotation);
                            persistAnnotations();
                            updateAnnotationsList();
                            addAnnotationElement(blankPageEl, annotation, pageInfo);
                            pendingDrawingShape.element.remove();
                            pendingDrawingShape = null;
                            setSelection(annotation);
                            setStatus('Shape added to new page. Click Save to keep changes.', 'ok');
                        });
                        
                        // Insert after the reference page in main viewer
                        if (insertAfterMainPage && insertAfterMainPage.nextSibling) {
                            mainViewer.insertBefore(blankPageEl, insertAfterMainPage.nextSibling);
                        } else if (insertAfterMainPage) {
                            mainViewer.appendChild(blankPageEl);
                        }
                        
                        // Scroll to the new page
                        blankPageEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        
                        setStatus('Blank page added after page ' + pageNumber + '. Click to add annotations, then save.', 'ok');
                    } catch (error) {
                        console.error('Error preparing blank page:', error);
                        setStatus('Error preparing page: ' + error.message, 'err');
                    }
                });

                // Rotate page button handler
                hoverMenu.querySelector('.rotate-page-btn').addEventListener('click', async () => {
                    const pageIndex = pageNumber - 1;
                    console.log(`[ROTATE] Page ${pageNumber} rotation requested`);
                    
                    // Update rotation state (90 degrees clockwise each click)
                    pageRotations[pageIndex] = (pageRotations[pageIndex] || 0) + 90;
                    if (pageRotations[pageIndex] >= 360) {
                        pageRotations[pageIndex] = 0;
                    }
                    
                    // Re-render the page with rotation
                    const pageWrapper = viewer.querySelector(`.page[data-page-index="${pageIndex}"]`);
                    if (pageWrapper && pdfjsDocument) {
                        try {
                            const page = await pdfjsDocument.getPage(pageNumber);
                            // Apply additional rotation on top of PDF's existing rotation
                            const existingRotation = page.rotate || 0;
                            const totalRotation = existingRotation + pageRotations[pageIndex];
                            const viewport = page.getViewport({ scale: currentScale, rotation: totalRotation });
                            
                            // Find the canvas in the page wrapper
                            const canvas = pageWrapper.querySelector('canvas');
                            if (canvas) {
                                const context = canvas.getContext('2d');
                                canvas.width = viewport.width;
                                canvas.height = viewport.height;
                                
                                // Re-render the page with rotation
                                await page.render({ canvasContext: context, viewport }).promise;
                                
                                // Update wrapper dimensions
                                pageWrapper.style.width = viewport.width + 'px';
                                pageWrapper.style.height = viewport.height + 'px';
                                
                                // Re-render the thumbnail with rotation
                                const thumbWidth = 240;
                                const thumbViewport = page.getViewport({ scale: thumbWidth / viewport.width, rotation: totalRotation });
                                thumbCanvas.width = thumbViewport.width;
                                thumbCanvas.height = thumbViewport.height;
                                const thumbCtx = thumbCanvas.getContext('2d');
                                thumbCtx.imageSmoothingEnabled = true;
                                thumbCtx.imageSmoothingQuality = 'high';
                                
                                // Clear any previous CSS transforms
                                thumbCanvas.style.transform = '';
                                
                                // Render the rotated page to the thumbnail
                                await page.render({ 
                                    canvasContext: thumbCtx, 
                                    viewport: thumbViewport 
                                }).promise;
                            }
                        } catch (error) {
                            console.error('Error re-rendering rotated page:', error);
                        }
                    }
                    
                    setStatus(`Page ${pageNumber} rotated ${pageRotations[pageIndex]}°. Click Save PDF to apply.`, 'ok');
                });

                // Delete page button handler
                hoverMenu.querySelector('.delete-page-btn').addEventListener('click', async () => {
                    console.log(`[DELETE] Page ${pageNumber} delete requested`);
                    
                    // Count visible (non-deleted) pages
                    const visiblePageCount = totalPages - pendingDeletedPages.length;
                    
                    if (visiblePageCount <= 1) {
                        alert('Cannot delete the last page of the document.');
                        return;
                    }
                    
                    if (!confirm('⚠️ DELETE PAGE ' + pageNumber + '?\n\nThis page will be removed. Click Save PDF to make the deletion permanent.\n\nAre you sure?')) {
                        console.log(`[DELETE] Page ${pageNumber} delete cancelled by user`);
                        return;
                    }
                    
                    console.log(`[DELETE] Page ${pageNumber} delete confirmed by user`);
                    
                    const pageIndex = pageNumber - 1;
                    
                    // Track this page for deletion on save
                    if (!pendingDeletedPages.includes(pageIndex)) {
                        pendingDeletedPages.push(pageIndex);
                    }
                    
                    // Remove annotations for this page from the local array
                    const annotationsToRemove = annotations.filter(a => a.pageIndex === pageIndex);
                    annotationsToRemove.forEach(ann => {
                        const idx = annotations.indexOf(ann);
                        if (idx >= 0) annotations.splice(idx, 1);
                    });
                    updateAnnotationsList();
                    persistAnnotations();
                    
                    // Remove text items for this page from pdfTextItems
                    for (let i = pdfTextItems.length - 1; i >= 0; i--) {
                        if (pdfTextItems[i].pageIndex === pageIndex) {
                            pdfTextItems.splice(i, 1);
                        }
                    }
                    
                    // Remove page from main viewer DOM
                    const pageWrapper = viewer.querySelector(`.page[data-page-index="${pageIndex}"]`);
                    if (pageWrapper) {
                        pageWrapper.remove();
                    }
                    
                    // Remove thumbnail from sidebar
                    thumb.remove();
                    
                    // Update page numbers on remaining thumbnails
                    const remainingThumbs = pageList.querySelectorAll('.page-thumb');
                    remainingThumbs.forEach((t, idx) => {
                        const labelEl = t.querySelector('span');
                        if (labelEl) {
                            // Recalculate visual page number (excluding deleted pages)
                            const originalIndex = parseInt(t.dataset.pageIndex || idx);
                            let visualNum = 1;
                            for (let i = 0; i <= originalIndex; i++) {
                                if (!pendingDeletedPages.includes(i)) {
                                    if (i === originalIndex) break;
                                    visualNum++;
                                }
                            }
                        }
                    });
                    
                    setStatus(`Page ${pageNumber} marked for deletion. Click Save PDF to apply.`, 'ok');
                });

                thumb.appendChild(thumbCanvas);
                thumb.appendChild(label);
                thumb.appendChild(hoverMenu);

                thumb.addEventListener('click', () => {
                    pageList.querySelectorAll('.page-thumb-active').forEach((item) => {
                        item.classList.remove('page-thumb-active', 'border-emerald-500', 'ring-2', 'ring-emerald-500/30');
                        item.classList.add('border-gray-600/50');
                    });
                    thumb.classList.remove('border-gray-600/50');
                    thumb.classList.add('page-thumb-active', 'border-emerald-500', 'ring-2', 'ring-emerald-500/30');
                    const target = viewer.querySelector(
                        `.page[data-page-index="${pageNumber - 1}"], .overlay-page[data-page-number="${pageNumber}"]`
                    );
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                    // Close sidebar on mobile after selection
                    if (window.innerWidth < 1024) {
                        sidebar.classList.add('-translate-x-full');
                    }
                });

                if (pageNumber === 1) {
                    thumb.classList.remove('border-gray-600/50');
                    thumb.classList.add('page-thumb-active', 'border-emerald-500', 'ring-2', 'ring-emerald-500/30');
                }
                pageList.appendChild(thumb);
            }

            function getVisiblePage() {
                // Get the viewer's scroll position
                const viewerRect = viewer.getBoundingClientRect();
                const viewerMidY = viewerRect.top + viewerRect.height / 2;
                
                // Find the page that's closest to the middle of the viewport
                const pages = viewer.querySelectorAll('.page, .overlay-page');
                let closestPage = pages[0];
                let closestDistance = Infinity;
                
                pages.forEach(page => {
                    const pageRect = page.getBoundingClientRect();
                    const pageMidY = pageRect.top + pageRect.height / 2;
                    const distance = Math.abs(pageMidY - viewerMidY);
                    
                    if (distance < closestDistance) {
                        closestDistance = distance;
                        closestPage = page;
                    }
                });
                
                return closestPage;
            }

            function placeSignatureOnFirstPage() {
                const wrapper = getVisiblePage() || viewer.querySelector('.page') || viewer.querySelector('.overlay-page');
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
                    // Support both numeric page indices and pending page IDs
                    pageIndex: wrapper.dataset.pageIndex.startsWith('pending-') 
                        ? wrapper.dataset.pageIndex 
                        : (parseInt(wrapper.dataset.pageIndex || '0', 10) || 0),
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
                
                // Clear the viewer completely
                while (viewer.firstChild) {
                    viewer.removeChild(viewer.firstChild);
                }
                
                try {
                    await renderPdf();
                    // Re-apply gridlines after re-render
                    if (typeof renderGridlines === 'function') renderGridlines();
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
                const overlay = wrapper.querySelector('.overlay');
                if (!overlay) return;
                const canvas = wrapper.querySelector('canvas');
                if (!canvas) return;
                
                // Build pageInfo from canvas
                const pageInfo = {
                    scale: currentScale,
                    canvasHeight: canvas.height,
                };
                const pageIndex = annotation.pageIndex;

                // Hide the annotation element while editing
                if (annotation.element) {
                    annotation.element.style.visibility = 'hidden';
                }

                // Build opts from the annotation's current style
                const opts = {
                    fontFamily: annotation.fontFamily || defaultTextFont,
                    fontSizePx: Math.round(annotation.fontSize * currentScale),
                    textColor: annotation.textColor || '#111111',
                    bgColor: annotation.backgroundColor || 'transparent',
                    opacityVal: annotation.opacity ?? 1,
                    fontWeight: annotation.fontWeight || 'normal',
                    fontStyle: annotation.fontStyle || 'normal',
                    underline: Boolean(annotation.underline),
                    textAlign: annotation.textAlign || 'left',
                };

                const x = annotation.pdfX * currentScale;
                const y = pageInfo.canvasHeight - annotation.pdfY * currentScale;
                const dw = annotation.pdfWidth ? annotation.pdfWidth * currentScale : 0;
                const dh = annotation.pdfHeight ? annotation.pdfHeight * currentScale : 0;

                createTextBoxCreator(overlay, x, y, pageIndex, canvas, wrapper, pageInfo, opts, dw || undefined, dh || undefined, annotation);
            }

            // Helper to read current banner settings into an opts object
            function readBannerOpts() {
                const etbFont = document.getElementById('etb-font');
                const etbSize = document.getElementById('etb-size');
                const etbTextColor = document.getElementById('etb-text-color');
                const etbBgColor = document.getElementById('etb-bg-color');
                const etbOpacity = document.getElementById('etb-opacity');
                const etbBold = document.getElementById('etb-bold');
                const etbItalic = document.getElementById('etb-italic');
                const etbUnderline = document.getElementById('etb-underline');
                const etbAlign = document.getElementById('etb-align');
                return {
                    fontFamily: etbFont ? etbFont.value : defaultTextFont,
                    fontSizePx: etbSize ? Math.max(8, Math.min(144, parseInt(etbSize.value, 10))) : defaultTextSize,
                    textColor: etbTextColor ? etbTextColor.value : '#111111',
                    bgColor: etbBgColor ? etbBgColor.value : '#ffffff',
                    opacityVal: etbOpacity ? parseFloat(etbOpacity.value) : 1,
                    fontWeight: (etbBold && etbBold.classList.contains('active')) ? '700' : 'normal',
                    fontStyle: (etbItalic && etbItalic.classList.contains('active')) ? 'italic' : 'normal',
                    underline: etbUnderline ? etbUnderline.classList.contains('active') : false,
                    textAlign: etbAlign ? etbAlign.value : 'left',
                };
            }

            function createTextBoxCreator(overlay, x, y, pageIndex, canvas, wrapper, pageInfo, opts, dragW, dragH, editAnnotation) {
                const { fontFamily, fontSizePx, textColor, bgColor, opacityVal, fontWeight, fontStyle, underline, textAlign } = opts;
                
                // Remove any existing text-box-creator
                document.querySelectorAll('.text-box-creator').forEach(el => el.remove());
                removeActiveEditor();
                
                const box = document.createElement('div');
                box.className = 'text-box-creator';
                box.style.left = x + 'px';
                box.style.top = y + 'px';
                if (dragW && dragH) {
                    box.style.width = Math.max(60, dragW) + 'px';
                    box.style.height = Math.max(28, dragH) + 'px';
                    box.style.minWidth = '0';
                }
                
                // Input area – textarea for multi-line
                const input = document.createElement('textarea');
                input.className = 'tbc-input';
                input.placeholder = 'Type text here...';
                input.style.fontSize = fontSizePx + 'px';
                input.style.fontFamily = fontMap[fontFamily]?.css || 'inherit';
                input.style.color = textColor;
                input.style.fontWeight = fontWeight;
                input.style.fontStyle = fontStyle;
                input.style.textDecoration = underline ? 'underline' : 'none';
                input.style.textAlign = textAlign;
                input.style.opacity = opacityVal;
                if (dragH) input.style.height = Math.max(28, dragH - 8) + 'px';
                // Pre-fill text when editing an existing annotation
                if (editAnnotation) {
                    input.value = editAnnotation.text;
                }
                
                // Hover menu bar
                const menu = document.createElement('div');
                menu.className = 'tbc-menu';
                
                // Move/drag handle
                const moveBtn = document.createElement('button');
                moveBtn.className = 'tbc-menu-btn';
                moveBtn.title = 'Move';
                moveBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 9l-3 3 3 3"/><path d="M9 5l3-3 3 3"/><path d="M15 19l-3 3-3-3"/><path d="M19 9l3 3-3 3"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="12" y1="2" x2="12" y2="22"/></svg>';
                moveBtn.style.cursor = 'move';
                
                // Move drag functionality
                let moveDragStart = null;
                moveBtn.addEventListener('pointerdown', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    const boxRect = box.getBoundingClientRect();
                    const overlayRect = overlay.getBoundingClientRect();
                    moveDragStart = {
                        offsetX: e.clientX - boxRect.left,
                        offsetY: e.clientY - boxRect.top,
                        overlayLeft: overlayRect.left,
                        overlayTop: overlayRect.top,
                    };
                    const onMove = (me) => {
                        if (!moveDragStart) return;
                        const newX = me.clientX - moveDragStart.overlayLeft - moveDragStart.offsetX;
                        const newY = me.clientY - moveDragStart.overlayTop - moveDragStart.offsetY;
                        box.style.left = Math.max(0, newX) + 'px';
                        box.style.top = Math.max(0, newY) + 'px';
                    };
                    const onUp = () => {
                        moveDragStart = null;
                        window.removeEventListener('pointermove', onMove);
                        window.removeEventListener('pointerup', onUp);
                    };
                    window.addEventListener('pointermove', onMove);
                    window.addEventListener('pointerup', onUp);
                });
                
                // OK / confirm button
                const okBtn = document.createElement('button');
                okBtn.className = 'tbc-menu-btn tbc-ok';
                okBtn.title = 'Confirm (Enter)';
                okBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
                
                const divider1 = document.createElement('div');
                divider1.className = 'tbc-menu-divider';
                
                // Uppercase button
                const upperBtn = document.createElement('button');
                upperBtn.className = 'tbc-menu-btn';
                upperBtn.title = 'UPPERCASE';
                upperBtn.innerHTML = '<span style="font-weight:700;font-size:12px;">Tt</span>';
                upperBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    input.value = input.value.toUpperCase();
                    input.focus();
                });
                
                // Lowercase button
                const lowerBtn = document.createElement('button');
                lowerBtn.className = 'tbc-menu-btn';
                lowerBtn.title = 'lowercase';
                lowerBtn.innerHTML = '<span style="font-size:12px;">tl</span>';
                lowerBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    input.value = input.value.toLowerCase();
                    input.focus();
                });
                
                const divider2 = document.createElement('div');
                divider2.className = 'tbc-menu-divider';
                
                // Copy button
                const copyBtn = document.createElement('button');
                copyBtn.className = 'tbc-menu-btn';
                copyBtn.title = 'Copy text';
                copyBtn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
                copyBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (input.value.trim()) {
                        navigator.clipboard.writeText(input.value).then(() => {
                            setStatus('Text copied to clipboard.', 'ok');
                        });
                    }
                });
                
                // Delete button
                const deleteBtn = document.createElement('button');
                deleteBtn.className = 'tbc-menu-btn tbc-delete';
                deleteBtn.title = 'Cancel (Esc)';
                deleteBtn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';
                
                menu.appendChild(moveBtn);
                menu.appendChild(okBtn);
                menu.appendChild(divider1);
                menu.appendChild(upperBtn);
                menu.appendChild(lowerBtn);
                menu.appendChild(divider2);
                menu.appendChild(copyBtn);
                menu.appendChild(deleteBtn);
                
                box.appendChild(menu);
                box.appendChild(input);
                
                // Commit text – reads CURRENT banner settings so live changes are captured
                let finished = false;
                const commitText = () => {
                    if (finished) return;
                    finished = true;
                    const text = input.value.trim();
                    if (text) {
                        const boxLeft = parseFloat(box.style.left);
                        const boxTop = parseFloat(box.style.top);
                        // Read live values from banner
                        const curFont = document.getElementById('etb-font')?.value || fontFamily;
                        const curSize = parseInt(document.getElementById('etb-size')?.value, 10) || fontSizePx;
                        const curTextColor = document.getElementById('etb-text-color')?.value || textColor;
                        const curBgColor = document.getElementById('etb-bg-color')?.value || bgColor;
                        const curOpacity = parseFloat(document.getElementById('etb-opacity')?.value) || opacityVal;
                        const curBold = document.getElementById('etb-bold')?.classList.contains('active') ? '700' : 'normal';
                        const curItalic = document.getElementById('etb-italic')?.classList.contains('active') ? 'italic' : 'normal';
                        const curUnderline = document.getElementById('etb-underline')?.classList.contains('active') || false;
                        const curAlign = document.getElementById('etb-align')?.value || textAlign;
                        
                        if (editAnnotation) {
                            // Update existing annotation
                            editAnnotation.text = text;
                            editAnnotation.pdfX = boxLeft / currentScale;
                            editAnnotation.pdfY = (canvas.height - boxTop) / currentScale;
                            editAnnotation.fontSize = curSize / currentScale;
                            editAnnotation.fontFamily = curFont;
                            editAnnotation.textColor = curTextColor;
                            editAnnotation.backgroundColor = curBgColor === '#ffffff' ? 'transparent' : curBgColor;
                            editAnnotation.fontWeight = curBold;
                            editAnnotation.fontStyle = curItalic;
                            editAnnotation.underline = curUnderline;
                            editAnnotation.textAlign = curAlign;
                            editAnnotation.opacity = curOpacity;
                            const boxW = box.offsetWidth;
                            const boxH = box.offsetHeight;
                            if (dragW || dragH) {
                                editAnnotation.pdfWidth = boxW / currentScale;
                                editAnnotation.pdfHeight = boxH / currentScale;
                            }
                            if (editAnnotation.element) {
                                editAnnotation.element.style.visibility = '';
                            }
                            normalizeTextAnnotation(editAnnotation);
                            applyAnnotationStyle(editAnnotation);
                            setSelection(editAnnotation);
                            persistAnnotations();
                            saveAnnotationToDatabase(editAnnotation);
                            setStatus('Text updated. Click Save to keep changes.', 'ok');
                        } else {
                            // Create new annotation
                            const annotation = {
                                id: generateAnnotationId(),
                                text,
                                pageIndex: pageIndex,
                                pdfX: boxLeft / currentScale,
                                pdfY: (canvas.height - boxTop) / currentScale,
                                fontSize: curSize / currentScale,
                                fontFamily: curFont,
                                type: 'text',
                                textColor: curTextColor,
                                backgroundColor: curBgColor === '#ffffff' ? 'transparent' : curBgColor,
                                fontWeight: curBold,
                                fontStyle: curItalic,
                                underline: curUnderline,
                                textAlign: curAlign,
                                opacity: curOpacity,
                                rotation: 0,
                            };
                            // Store bounding box size if the box was drag-sized
                            const boxW = box.offsetWidth;
                            const boxH = box.offsetHeight;
                            if (dragW || dragH) {
                                annotation.pdfWidth = boxW / currentScale;
                                annotation.pdfHeight = boxH / currentScale;
                            }
                            normalizeTextAnnotation(annotation);
                            annotations.push(annotation);
                            persistAnnotations();
                            updateAnnotationsList();
                            addAnnotationElement(wrapper, annotation, pageInfo);
                            saveAnnotationToDatabase(annotation);
                            setSelection(annotation);
                            setStatus('Text added. Click Save to keep changes.', 'ok');
                        }
                    } else if (editAnnotation) {
                        // Empty text on edit — restore visibility
                        if (editAnnotation.element) {
                            editAnnotation.element.style.visibility = '';
                        }
                    }
                    box.remove();
                    if (activeEditor === box) activeEditor = null;
                    document.removeEventListener('pointerdown', outsideClickHandler, true);
                };
                
                const cancelBox = () => {
                    if (finished) return;
                    finished = true;
                    // Restore annotation visibility if editing
                    if (editAnnotation && editAnnotation.element) {
                        editAnnotation.element.style.visibility = '';
                    }
                    box.remove();
                    if (activeEditor === box) activeEditor = null;
                    document.removeEventListener('pointerdown', outsideClickHandler, true);
                };
                
                // Click outside to confirm (request #2)
                const outsideClickHandler = (e) => {
                    if (finished) return;
                    if (box.contains(e.target)) return;
                    // Don't confirm if clicking on edit-text-banner controls
                    const banner = document.getElementById('edit-text-banner');
                    if (banner && banner.contains(e.target)) return;
                    commitText();
                };
                // Delay so the drag-up event doesn't immediately fire
                setTimeout(() => {
                    if (!finished) document.addEventListener('pointerdown', outsideClickHandler, true);
                }, 100);
                
                okBtn.addEventListener('click', (e) => { e.stopPropagation(); commitText(); });
                deleteBtn.addEventListener('click', (e) => { e.stopPropagation(); cancelBox(); });
                
                input.addEventListener('keydown', (e) => {
                    e.stopPropagation();
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        commitText();
                    } else if (e.key === 'Escape') {
                        cancelBox();
                    }
                });
                
                // Prevent clicks inside the box from bubbling to overlay
                box.addEventListener('click', (e) => e.stopPropagation());
                box.addEventListener('pointerdown', (e) => {
                    if (e.target !== moveBtn && !moveBtn.contains(e.target)) {
                        e.stopPropagation();
                    }
                });
                
                // Store reference for live banner updates
                box._tbcInput = input;
                box._tbcOpts = opts;
                
                activeEditor = box;
                overlay.appendChild(box);
                input.focus();
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
                    // Wrap text span in a rotation container so menu/handles stay unrotated
                    const textWrapper = document.createElement('div');
                    textWrapper.className = 'text-content-wrapper';
                    textWrapper.style.display = 'inline-block';
                    textWrapper.style.width = '100%';
                    textWrapper.appendChild(textSpan);
                    label.appendChild(textWrapper);
                    annotation.textWrapper = textWrapper;
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
                    // Hide old delete button (we use tbc-menu instead)
                    deleteBtn.style.display = 'none';
                    
                    // Build annotation tbc-menu (floating bar shown on selection) - request #6
                    const tbcMenu = document.createElement('div');
                    tbcMenu.className = 'annotation-tbc-menu';
                    
                    // Move handle
                    const tbcMove = document.createElement('button');
                    tbcMove.className = 'tbc-menu-btn';
                    tbcMove.title = 'Move';
                    tbcMove.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 9l-3 3 3 3"/><path d="M9 5l3-3 3 3"/><path d="M15 19l-3 3-3-3"/><path d="M19 9l3 3-3 3"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="12" y1="2" x2="12" y2="22"/></svg>';
                    tbcMove.style.cursor = 'move';
                    
                    // Edit (double-click inline edit)
                    const tbcEdit = document.createElement('button');
                    tbcEdit.className = 'tbc-menu-btn tbc-ok';
                    tbcEdit.title = 'Edit text';
                    tbcEdit.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
                    tbcEdit.addEventListener('click', (e) => {
                        e.stopPropagation();
                        startInlineEdit(wrapper, annotation);
                    });
                    
                    const tbcDiv1 = document.createElement('div');
                    tbcDiv1.className = 'tbc-menu-divider';
                    
                    // Uppercase
                    const tbcUpper = document.createElement('button');
                    tbcUpper.className = 'tbc-menu-btn';
                    tbcUpper.title = 'UPPERCASE';
                    tbcUpper.innerHTML = '<span style="font-weight:700;font-size:12px;">Tt</span>';
                    tbcUpper.addEventListener('click', (e) => {
                        e.stopPropagation();
                        annotation.text = annotation.text.toUpperCase();
                        applyAnnotationStyle(annotation);
                        persistAnnotations();
                        saveAnnotationToDatabase(annotation);
                    });
                    
                    // Lowercase
                    const tbcLower = document.createElement('button');
                    tbcLower.className = 'tbc-menu-btn';
                    tbcLower.title = 'lowercase';
                    tbcLower.innerHTML = '<span style="font-size:12px;">tl</span>';
                    tbcLower.addEventListener('click', (e) => {
                        e.stopPropagation();
                        annotation.text = annotation.text.toLowerCase();
                        applyAnnotationStyle(annotation);
                        persistAnnotations();
                        saveAnnotationToDatabase(annotation);
                    });
                    
                    const tbcDiv2 = document.createElement('div');
                    tbcDiv2.className = 'tbc-menu-divider';
                    
                    // Copy
                    const tbcCopy = document.createElement('button');
                    tbcCopy.className = 'tbc-menu-btn';
                    tbcCopy.title = 'Copy text';
                    tbcCopy.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
                    tbcCopy.addEventListener('click', (e) => {
                        e.stopPropagation();
                        navigator.clipboard.writeText(annotation.text).then(() => {
                            setStatus('Text copied to clipboard.', 'ok');
                        });
                    });
                    
                    // Delete
                    const tbcDel = document.createElement('button');
                    tbcDel.className = 'tbc-menu-btn tbc-delete';
                    tbcDel.title = 'Delete';
                    tbcDel.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';
                    tbcDel.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const ov = wrapper.querySelector('.overlay');
                        if (ov) ov.removeChild(label);
                        const idx = annotations.indexOf(annotation);
                        if (idx >= 0) annotations.splice(idx, 1);
                        if (selectedAnnotation === annotation) selectedAnnotation = null;
                        updateSelectionBar();
                        updateEditTextBanner();
                        persistAnnotations();
                        updateAnnotationsList();
                        setStatus('Text deleted.', 'ok');
                    });
                    
                    tbcMenu.appendChild(tbcMove);
                    tbcMenu.appendChild(tbcEdit);
                    tbcMenu.appendChild(tbcDiv1);
                    tbcMenu.appendChild(tbcUpper);
                    tbcMenu.appendChild(tbcLower);
                    tbcMenu.appendChild(tbcDiv2);
                    tbcMenu.appendChild(tbcCopy);
                    tbcMenu.appendChild(tbcDel);
                    label.appendChild(tbcMenu);
                    
                    // Text annotation rotate handle (request #4)
                    const textRotateHandle = document.createElement('div');
                    textRotateHandle.className = 'text-rotate-handle';
                    textRotateHandle.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>';
                    textRotateHandle.title = 'Rotate text';
                    
                    let textIsRotating = false;
                    let textRotateStartAngle = 0;
                    let textRotateStartRotation = 0;
                    let textRotateCenterX = 0;
                    let textRotateCenterY = 0;
                    
                    const updateTextRotateHandlePos = () => {
                        textRotateHandle.style.bottom = '-52px';
                        textRotateHandle.style.left = '50%';
                        textRotateHandle.style.transform = 'translateX(-50%)';
                        textRotateHandle.style.top = '';
                    };
                    
                    textRotateHandle.addEventListener('pointerdown', (e) => {
                        e.stopPropagation();
                        e.preventDefault();
                        textIsRotating = true;
                        label.dataset.rotating = 'true';
                        textRotateHandle.style.cursor = 'grabbing';
                        
                        // Cache center using offset position (unaffected by CSS rotation)
                        const overlayRect = overlay.getBoundingClientRect();
                        const labelLeft = label.offsetLeft;
                        const labelTop = label.offsetTop;
                        const w = label.offsetWidth;
                        const h = label.offsetHeight;
                        textRotateCenterX = overlayRect.left + labelLeft + w / 2;
                        textRotateCenterY = overlayRect.top + labelTop + h / 2;
                        
                        const dx = e.clientX - textRotateCenterX;
                        const dy = e.clientY - textRotateCenterY;
                        textRotateStartAngle = Math.atan2(dy, dx) * (180 / Math.PI);
                        textRotateStartRotation = annotation.rotation || 0;
                        
                        // Fixed orbit radius based on element size at drag start
                        const orbitDist = (Math.max(w, h) / 2) + 40;
                        
                        // Get textWrapper reference
                        const tw = annotation.textWrapper || label.querySelector('.text-content-wrapper');
                        
                        const onRotateMove = (me) => {
                            if (!textIsRotating) return;
                            const ddx = me.clientX - textRotateCenterX;
                            const ddy = me.clientY - textRotateCenterY;
                            const curAngle = Math.atan2(ddy, ddx) * (180 / Math.PI);
                            let newRot = textRotateStartRotation + (curAngle - textRotateStartAngle);
                            while (newRot < 0) newRot += 360;
                            while (newRot >= 360) newRot -= 360;
                            annotation.rotation = newRot;
                            // Apply rotation to textWrapper only (not label)
                            if (tw) {
                                tw.style.transform = `rotate(${newRot}deg)`;
                                tw.style.transformOrigin = 'center center';
                            }
                            
                            // Move handle in orbit (fixed radius)
                            const rad = curAngle * (Math.PI / 180);
                            const hx = Math.cos(rad) * orbitDist;
                            const hy = Math.sin(rad) * orbitDist;
                            textRotateHandle.style.bottom = 'auto';
                            textRotateHandle.style.left = (w / 2 + hx - 13) + 'px';
                            textRotateHandle.style.top = (h / 2 + hy - 13) + 'px';
                            textRotateHandle.style.transform = 'none';
                        };
                        
                        const onRotateUp = () => {
                            textIsRotating = false;
                            delete label.dataset.rotating;
                            textRotateHandle.style.cursor = 'grab';
                            updateTextRotateHandlePos();
                            persistAnnotations();
                            saveAnnotationToDatabase(annotation);
                            setStatus('Text rotated. Click Save to keep changes.', 'ok');
                            window.removeEventListener('pointermove', onRotateMove);
                            window.removeEventListener('pointerup', onRotateUp);
                        };
                        
                        window.addEventListener('pointermove', onRotateMove);
                        window.addEventListener('pointerup', onRotateUp);
                    });
                    
                    label.appendChild(textRotateHandle);
                    
                    // Text annotation resize handles (request #5)
                    const textResizePositions = [
                        { cls: 'tr-e',  dir: 'e' },
                        { cls: 'tr-w',  dir: 'w' },
                        { cls: 'tr-s',  dir: 's' },
                        { cls: 'tr-se', dir: 'se' },
                        { cls: 'tr-sw', dir: 'sw' },
                    ];
                    textResizePositions.forEach(pos => {
                        const rh = document.createElement('div');
                        rh.className = `text-resize-handle ${pos.cls}`;
                        rh.addEventListener('pointerdown', (e) => {
                            e.stopPropagation();
                            e.preventDefault();
                            label.dataset.resizing = 'true';
                            const startX = e.clientX;
                            const startY = e.clientY;
                            const startW = label.offsetWidth;
                            const startH = label.offsetHeight;
                            const startLeft = label.offsetLeft;
                            const startTop = label.offsetTop;
                            const dir = pos.dir;
                            
                            const onResizeMove = (me) => {
                                const dx = me.clientX - startX;
                                const dy = me.clientY - startY;
                                let nw = startW, nh = startH, nl = startLeft, nt = startTop;
                                if (dir.includes('e')) nw = Math.max(30, startW + dx);
                                if (dir.includes('w')) {
                                    nw = Math.max(30, startW - dx);
                                    nl = startLeft + (startW - nw);
                                }
                                if (dir.includes('s')) nh = Math.max(16, startH + dy);
                                label.style.width = nw + 'px';
                                label.style.height = nh + 'px';
                                label.style.left = nl + 'px';
                                label.style.top = nt + 'px';
                                annotation.pdfWidth = nw / pageInfo.scale;
                                annotation.pdfHeight = nh / pageInfo.scale;
                                annotation.pdfX = nl / pageInfo.scale;
                                annotation.pdfY = (pageInfo.canvasHeight - nt) / pageInfo.scale;
                            };
                            const onResizeUp = () => {
                                delete label.dataset.resizing;
                                // Mark the label as having explicit bounds
                                if (annotation.pdfWidth && annotation.pdfHeight) {
                                    label.classList.add('has-bounds');
                                }
                                window.removeEventListener('pointermove', onResizeMove);
                                window.removeEventListener('pointerup', onResizeUp);
                                persistAnnotations();
                                saveAnnotationToDatabase(annotation);
                                setStatus('Text resized. Click Save to keep changes.', 'ok');
                            };
                            window.addEventListener('pointermove', onResizeMove);
                            window.addEventListener('pointerup', onResizeUp);
                        });
                        label.appendChild(rh);
                    });
                    // If annotation already has bounds, add the class and set size
                    if (annotation.pdfWidth && annotation.pdfHeight) {
                        label.classList.add('has-bounds');
                    }
                } else if (annotation.type === 'shape') {
                    // Hide delete button for shapes (they have their own in action bar)
                    deleteBtn.style.display = 'none';
                }

                const overlay = wrapper.querySelector('.overlay');
                if (!overlay) {
                    console.error('addAnnotationElement: No .overlay found in wrapper', wrapper);
                    return;
                }
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
                        // Apply bounding box if present
                        if (annotation.pdfWidth && annotation.pdfHeight) {
                            label.style.width = (annotation.pdfWidth * pageInfo.scale) + 'px';
                            label.style.height = (annotation.pdfHeight * pageInfo.scale) + 'px';
                            label.style.overflow = 'hidden';
                            label.style.wordWrap = 'break-word';
                        }
                        // Alignment translateX on label (rotation goes on textWrapper)
                        const fontFamily = fontMap[annotation.fontFamily]?.css || 'inherit';
                        const fontSizePx = annotation.fontSize * pageInfo.scale;
                        const textWidth = annotation.pdfWidth ? (annotation.pdfWidth * pageInfo.scale) : measureAnnotationTextWidth(annotation.text, fontSizePx, fontFamily, annotation.fontWeight, annotation.fontStyle);
                        let translateX = 0;
                        if (!annotation.pdfWidth) {
                            if (annotation.textAlign === 'center') translateX = -textWidth / 2;
                            else if (annotation.textAlign === 'right') translateX = -textWidth;
                        }
                        label.style.transform = `translateX(${translateX}px)`;
                        // Apply rotation to text content wrapper only
                        const rot = annotation.rotation || 0;
                        const tw = annotation.textWrapper || label.querySelector('.text-content-wrapper');
                        if (tw) {
                            tw.style.transform = rot ? `rotate(${rot}deg)` : '';
                            tw.style.transformOrigin = 'center center';
                        }
                    }
                };
                updatePosition();

                let dragStart = null;
                let dragMoved = false;
                let dragFromMenu = false;

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
                        // Single click without drag: if text annotation and not from tbc-menu, enter inline edit
                        if ((annotation.type === 'text' || !annotation.type) && !dragFromMenu) {
                            setSelection(annotation);
                            startInlineEdit(wrapper, annotation);
                        } else {
                            setSelection(annotation);
                        }
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
                    // Don't start dragging if clicking on rotate handle or tbc-menu (except move button)
                    if (event.target.classList.contains('rotate-handle') || event.target.classList.contains('text-rotate-handle')) {
                        return;
                    }
                    const closestMenu = event.target.closest('.annotation-tbc-menu');
                    if (closestMenu) {
                        // Allow drag from the move button only
                        const moveBtn = event.target.closest('[title="Move"]');
                        if (!moveBtn) return;
                    }
                    if (event.target.closest('.text-rotate-handle') || event.target.closest('.text-resize-handle')) {
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
                    dragFromMenu = !!event.target.closest('.annotation-tbc-menu');
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
                const wrappers = viewer.querySelectorAll('.page-wrapper, .page');
                wrappers.forEach((wrapper) => {
                    const overlay = wrapper.querySelector('.overlay');
                    const canvas = wrapper.querySelector('canvas');
                    if (!overlay || !canvas) return;
                    
                    const pageIndex = parseInt(wrapper.dataset.pageIndex || '0', 10);
                    
                    // Remove all annotation labels
                    const labels = overlay.querySelectorAll('.annotation-label');
                    labels.forEach(label => label.remove());
                    
                    // Build pageInfo from canvas
                    const pageInfo = {
                        scale: currentScale,
                        canvasHeight: canvas.height,
                    };
                    
                    // Re-add them in order
                    const pageAnnotations = annotations.filter(a => a.pageIndex === pageIndex);
                    pageAnnotations.forEach(annotation => {
                        addAnnotationElement(wrapper, annotation, pageInfo);
                    });
                });
                
                // Restore selection
                if (selectedAnnotation) {
                    setSelection(selectedAnnotation);
                }
            }

            async function renderPdf() {
                // Clear the viewer completely
                while (viewer.firstChild) {
                    viewer.removeChild(viewer.firstChild);
                }
                
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
                    // Clear page list thumbnails
                    while (pageList.firstChild) {
                        pageList.removeChild(pageList.firstChild);
                    }
                }
                totalPages = pdf.numPages;
                updatePageControls();
                
                for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++) {
                    const page = await pdf.getPage(pageNumber);
                    
                    // Don't initialize pageRotations - it should only track NEW rotations
                    // The PDF's existing rotation is already baked in
                    const pageIndex = pageNumber - 1;
                    if (!pageRotations[pageIndex]) {
                        pageRotations[pageIndex] = 0;
                    }
                    
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

                    // Click on empty overlay: deselect when not in text mode
                    overlay.addEventListener('click', (event) => {
                        if (event.target !== overlay) return;
                        if (toolMode !== 'text') {
                            setSelection(null);
                            removeActiveEditor();
                        }
                    });

                    // Drag-to-create text box (request #1)
                    (function setupTextDrag(ov, pn, cv, wr, pi) {
                        let dragState = null;
                        ov.addEventListener('pointerdown', (e) => {
                            if (toolMode !== 'text') return;
                            if (e.target !== ov) return;
                            e.preventDefault();
                            ov.setPointerCapture(e.pointerId);
                            const rect = ov.getBoundingClientRect();
                            const startX = e.clientX - rect.left;
                            const startY = e.clientY - rect.top;
                            const sel = document.createElement('div');
                            sel.className = 'text-drag-selection';
                            sel.style.left = startX + 'px';
                            sel.style.top = startY + 'px';
                            sel.style.width = '0';
                            sel.style.height = '0';
                            ov.appendChild(sel);
                            dragState = { startX, startY, sel, rect, moved: false };
                        });
                        ov.addEventListener('pointermove', (e) => {
                            if (!dragState) return;
                            const curX = e.clientX - dragState.rect.left;
                            const curY = e.clientY - dragState.rect.top;
                            const x = Math.min(dragState.startX, curX);
                            const y = Math.min(dragState.startY, curY);
                            const w = Math.abs(curX - dragState.startX);
                            const h = Math.abs(curY - dragState.startY);
                            dragState.sel.style.left = x + 'px';
                            dragState.sel.style.top = y + 'px';
                            dragState.sel.style.width = w + 'px';
                            dragState.sel.style.height = h + 'px';
                            if (w > 5 || h > 5) dragState.moved = true;
                        });
                        ov.addEventListener('pointerup', (e) => {
                            if (!dragState) return;
                            const ds = dragState;
                            dragState = null;
                            const curX = e.clientX - ds.rect.left;
                            const curY = e.clientY - ds.rect.top;
                            const bx = Math.min(ds.startX, curX);
                            const by = Math.min(ds.startY, curY);
                            const bw = Math.abs(curX - ds.startX);
                            const bh = Math.abs(curY - ds.startY);
                            ds.sel.remove();
                            const opts = readBannerOpts();
                            if (ds.moved && bw > 20 && bh > 10) {
                                createTextBoxCreator(ov, bx, by, pn - 1, cv, wr, pi, opts, bw, bh);
                            } else {
                                createTextBoxCreator(ov, bx, by, pn - 1, cv, wr, pi, opts);
                            }
                        });
                    })(overlay, pageNumber, canvas, wrapper, pageInfo);

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

            // Helper: create a PDF ExtGState dictionary with proper PDFName values
            // CRITICAL: context.obj({Type: 'ExtGState'}) creates string value (ExtGState)
            // but PDF spec requires name value /ExtGState. MuPDF rejects the string form.
            function createExtGState(pdfDoc, opacity) {
                const dict = pdfDoc.context.obj({});
                dict.set(PDFLib.PDFName.of('Type'), PDFLib.PDFName.of('ExtGState'));
                dict.set(PDFLib.PDFName.of('CA'), pdfDoc.context.obj(opacity));
                dict.set(PDFLib.PDFName.of('ca'), pdfDoc.context.obj(opacity));
                return dict;
            }

            // Helper: add a content stream to a page's Contents array
            function appendContentStream(pdfDoc, page, opsString) {
                const contentBytes = new TextEncoder().encode(opsString);
                const contentStream = pdfDoc.context.flateStream(contentBytes);
                const contentStreamRef = pdfDoc.context.register(contentStream);
                const contents = page.node.Contents();
                const contentsArray = contents?.asArray?.() || (contents ? [contents] : []);
                page.node.set(PDFLib.PDFName.of('Contents'), pdfDoc.context.obj([...contentsArray, contentStreamRef]));
            }

            // Helper: register an ExtGState on a page and return its name for use in content streams
            function registerExtGState(pdfDoc, page, opacity) {
                const gsName = `GS${Date.now()}_${Math.random().toString(36).slice(2, 6)}`;
                const extGState = createExtGState(pdfDoc, opacity);
                const gsRef = pdfDoc.context.register(extGState);
                const resources = page.node.Resources();
                const existingGS = resources.lookup(PDFLib.PDFName.of('ExtGState'));
                const gsDict = existingGS || pdfDoc.context.obj({});
                if (!existingGS) {
                    resources.set(PDFLib.PDFName.of('ExtGState'), gsDict);
                }
                gsDict.set(PDFLib.PDFName.of(gsName), gsRef);
                return gsName;
            }

            async function savePdf() {
                const hasTextEdits = pdfTextItems.some((item) => item.modified);
                const hasRotations = Object.keys(pageRotations).some(pageIndex => pageRotations[pageIndex] !== 0);
                const hasDeletedPages = pendingDeletedPages.length > 0;
                const hasNewPages = pendingNewPages.length > 0;
                
                if (!annotations.length && !hasTextEdits && !hasRotations && !hasDeletedPages && !hasNewPages) {
                    setStatus('No changes to save.', 'err');
                    return;
                }

                // Log what we're about to save
                console.log('=== SAVE PDF - DATA BEING SENT ===');
                console.log('Pending deleted pages:', pendingDeletedPages);
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
                // IMPORTANT: Always use the original PDF URL, not cleanPdfUrl, to preserve text content
                const saveFromUrl = pdfUrl; // Use original PDF, not basePdfUrl which might be cleanPdfUrl
                const joiner = saveFromUrl.includes('?') ? '&' : '?';
                const currentPdfUrl = `${saveFromUrl}${joiner}v=${pdfVersion}`;
                const currentPdfResponse = await fetch(currentPdfUrl);
                const currentPdfBytes = await currentPdfResponse.arrayBuffer();
                
                let pdfBytesForLib = currentPdfBytes;
                
                // CRITICAL: Apply rotations BEFORE pdf-lib touches the PDF
                // pdf-lib creates structures incompatible with PyMuPDF's wrap_contents()
                // So we must rotate first, then let pdf-lib add annotations
                if (hasRotations) {
                    // Build rotation data to send
                    const rotationData = {};
                    for (const [pageIndexStr, rotation] of Object.entries(pageRotations)) {
                        if (rotation !== 0) {
                            // Negate rotation because PyMuPDF rotates counter-clockwise
                            rotationData[pageIndexStr] = -rotation;
                        }
                    }
                    
                    console.log('Applying rotations BEFORE pdf-lib:', rotationData);
                    
                    const rotateFormData = new FormData();
                    rotateFormData.append('pdf', new Blob([currentPdfBytes], { type: 'application/pdf' }));
                    rotateFormData.append('rotations', JSON.stringify(rotationData));
                    
                    const rotateResponse = await fetch('{{ route("documents.applyRotations", $document) }}', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': csrfToken },
                        body: rotateFormData
                    });
                    
                    if (!rotateResponse.ok) {
                        console.error('Failed to apply rotations');
                        setStatus('Failed to apply rotations', 'err');
                        setSaveSpinner(false);
                        return;
                    }
                    
                    pdfBytesForLib = await rotateResponse.arrayBuffer();
                    console.log('Rotations applied, ready for annotations');
                    
                    // Clear pageRotations since they've been applied
                    for (const key in pageRotations) {
                        delete pageRotations[key];
                    }
                } else {
                    console.log('No pending rotations, using PDF as-is');
                }
                
                // Load the PDF (rotated or original) with pdf-lib for annotations
                const pdfDoc = await PDFLib.PDFDocument.load(pdfBytesForLib);
                
                // Track deleted pages for adjusting new page insertAfter values
                let deletedPagesForNewPageAdjustment = [];
                
                // Delete pending pages BEFORE adding new ones or annotations
                // Must delete in reverse order to maintain correct indices
                if (pendingDeletedPages.length > 0) {
                    console.log('Deleting pending pages:', pendingDeletedPages);
                    
                    // Sort in descending order so we delete from the end first
                    const sortedDeleted = [...pendingDeletedPages].sort((a, b) => b - a);
                    
                    for (const pageIndex of sortedDeleted) {
                        if (pageIndex >= 0 && pageIndex < pdfDoc.getPageCount()) {
                            console.log(`Removing page at index ${pageIndex}`);
                            pdfDoc.removePage(pageIndex);
                        }
                    }
                    
                    // Update annotation page indices to account for deleted pages
                    // Pages after deleted pages shift down
                    for (const ann of annotations) {
                        if (typeof ann.pageIndex === 'number') {
                            let offset = 0;
                            for (const deletedIdx of pendingDeletedPages) {
                                if (deletedIdx < ann.pageIndex) {
                                    offset++;
                                }
                            }
                            if (offset > 0) {
                                console.log(`Adjusting annotation pageIndex from ${ann.pageIndex} to ${ann.pageIndex - offset}`);
                                ann.pageIndex = ann.pageIndex - offset;
                            }
                        }
                    }
                    
                    // Update pdfTextItems page indices as well
                    for (const item of pdfTextItems) {
                        if (typeof item.pageIndex === 'number') {
                            let offset = 0;
                            for (const deletedIdx of pendingDeletedPages) {
                                if (deletedIdx < item.pageIndex) {
                                    offset++;
                                }
                            }
                            if (offset > 0) {
                                item.pageIndex = item.pageIndex - offset;
                            }
                        }
                    }
                    
                    // Save a copy for adjusting new page insertAfter values
                    deletedPagesForNewPageAdjustment = [...pendingDeletedPages];
                    
                    // Clear the deleted pages array
                    pendingDeletedPages.length = 0;
                }
                
                // Add pending new pages using pdf-lib
                // Build a map of pending page IDs to their final page indices
                const pendingPageIndexMap = {};
                
                if (pendingNewPages.length > 0) {
                    console.log('Adding pending new pages:', pendingNewPages);
                    
                    // Sort by insertAfter in ASCENDING order for correct index calculation
                    const sortedPending = [...pendingNewPages].sort((a, b) => a.insertAfter - b.insertAfter);
                    
                    // Calculate final indices accounting for deleted pages and previously inserted pages
                    let insertedCount = 0;
                    for (const pending of sortedPending) {
                        // Adjust insertAfter to account for deleted pages before it
                        let adjustedInsertAfter = pending.insertAfter;
                        for (const deletedIdx of deletedPagesForNewPageAdjustment) {
                            if (deletedIdx <= pending.insertAfter) {
                                adjustedInsertAfter--;
                            }
                        }
                        // Ensure non-negative
                        adjustedInsertAfter = Math.max(-1, adjustedInsertAfter);
                        
                        const insertIndex = adjustedInsertAfter + 1 + insertedCount; // +1 for "after", + offset for previously inserted
                        
                        // Clamp to valid range
                        const clampedIndex = Math.min(Math.max(0, insertIndex), pdfDoc.getPageCount());
                        
                        pendingPageIndexMap[pending.id] = clampedIndex;
                        const newPage = pdfDoc.insertPage(clampedIndex, [pending.width, pending.height]);
                        console.log(`Inserted blank page "${pending.id}" at index ${clampedIndex} (${pending.width}x${pending.height})`);
                        insertedCount++;
                    }
                    
                    // Map annotations from pending pages to their final page indices
                    for (const ann of annotations) {
                        if (typeof ann.pageIndex === 'string' && ann.pageIndex.startsWith('pending-')) {
                            const finalIndex = pendingPageIndexMap[ann.pageIndex];
                            if (finalIndex !== undefined) {
                                console.log(`Mapping annotation from ${ann.pageIndex} to page ${finalIndex}`);
                                ann.pageIndex = finalIndex;
                            }
                        }
                    }
                    
                    // Clear pending pages since they've been added
                    pendingNewPages.length = 0;
                    
                    // Remove pending page placeholders from DOM (thumbnails and main viewer)
                    document.querySelectorAll('.pending-page').forEach(el => el.remove());
                }
                
                // Validate PDF structure - check if pages have proper resources
                try {
                    const pages = pdfDoc.getPages();
                    if (pages.length > 0) {
                        const testPage = pages[0];
                        const testResources = testPage.node.Resources();
                        if (!testResources) {
                            throw new Error('PDF structure is corrupted - page resources are missing');
                        }
                    }
                } catch (validationError) {
                    console.error('PDF validation failed:', validationError);
                    setStatus('Error: PDF is corrupted. Please re-upload the document.', 'err');
                    return;
                }
                
                // Don't apply rotations yet - do it after annotations are drawn
                
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
                            
                            // Create graphics state with opacity
                            const gsName = `GS${Date.now()}`;
                            const extGState = pdfDoc.context.obj({
                                Type: 'ExtGState',
                                CA: opacity, // Stroke alpha
                                ca: opacity  // Fill alpha
                            });
                            const gsRef = pdfDoc.context.register(extGState);
                            
                            // Add to page resources
                            const resources = page.node.Resources();
                            const existingGS = resources.lookup(PDFLib.PDFName.of('ExtGState'));
                            const gsDict = existingGS || pdfDoc.context.obj({});
                            if (!existingGS) {
                                resources.set(PDFLib.PDFName.of('ExtGState'), gsDict);
                            }
                            gsDict.set(PDFLib.PDFName.of(gsName), gsRef);
                            
                            // Build PDF operators
                            let ops = 'q\n'; // Save graphics state
                            ops += `/${gsName} gs\n`; // Apply graphics state with opacity
                            
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
                            
                            // Create graphics state with opacity
                            const gsName = `GS${Date.now()}`;
                            const extGState = pdfDoc.context.obj({
                                Type: 'ExtGState',
                                CA: opacity,
                                ca: opacity
                            });
                            const gsRef = pdfDoc.context.register(extGState);
                            const resources = page.node.Resources();
                            const existingGS = resources.lookup(PDFLib.PDFName.of('ExtGState'));
                            const gsDict = existingGS || pdfDoc.context.obj({});
                            if (!existingGS) {
                                resources.set(PDFLib.PDFName.of('ExtGState'), gsDict);
                            }
                            gsDict.set(PDFLib.PDFName.of(gsName), gsRef);
                            
                            let ops = 'q\n';
                            ops += `/${gsName} gs\n`;
                            
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
                            
                            // Create graphics state with opacity
                            const gsName = `GS${Date.now()}`;
                            const extGState = pdfDoc.context.obj({
                                Type: 'ExtGState',
                                CA: opacity,
                                ca: opacity
                            });
                            const gsRef = pdfDoc.context.register(extGState);
                            const resources = page.node.Resources();
                            const existingGS = resources.lookup(PDFLib.PDFName.of('ExtGState'));
                            const gsDict = existingGS || pdfDoc.context.obj({});
                            if (!existingGS) {
                                resources.set(PDFLib.PDFName.of('ExtGState'), gsDict);
                            }
                            gsDict.set(PDFLib.PDFName.of(gsName), gsRef);
                            
                            let ops = 'q\n';
                            ops += `/${gsName} gs\n`;
                            
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
                            
                            // Create graphics state with opacity
                            const gsName = `GS${Date.now()}`;
                            const extGState = pdfDoc.context.obj({
                                Type: 'ExtGState',
                                CA: opacity,
                                ca: opacity
                            });
                            const gsRef = pdfDoc.context.register(extGState);
                            const resources = page.node.Resources();
                            const existingGS = resources.lookup(PDFLib.PDFName.of('ExtGState'));
                            const gsDict = existingGS || pdfDoc.context.obj({});
                            if (!existingGS) {
                                resources.set(PDFLib.PDFName.of('ExtGState'), gsDict);
                            }
                            gsDict.set(PDFLib.PDFName.of(gsName), gsRef);
                            
                            let ops = 'q\n';
                            ops += `/${gsName} gs\n`;
                            
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
                            
                            // Create graphics state with opacity
                            const gsName = `GS${Date.now()}`;
                            const extGState = pdfDoc.context.obj({
                                Type: 'ExtGState',
                                CA: opacity,
                                ca: opacity
                            });
                            const gsRef = pdfDoc.context.register(extGState);
                            const resources = page.node.Resources();
                            const existingGS = resources.lookup(PDFLib.PDFName.of('ExtGState'));
                            const gsDict = existingGS || pdfDoc.context.obj({});
                            if (!existingGS) {
                                resources.set(PDFLib.PDFName.of('ExtGState'), gsDict);
                            }
                            gsDict.set(PDFLib.PDFName.of(gsName), gsRef);
                            
                            let ops = 'q\n';
                            ops += `/${gsName} gs\n`;
                            
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
                            
                            // Create graphics state with opacity
                            const gsName = `GS${Date.now()}`;
                            const extGState = pdfDoc.context.obj({
                                Type: 'ExtGState',
                                CA: opacity,
                                ca: opacity
                            });
                            const gsRef = pdfDoc.context.register(extGState);
                            const resources = page.node.Resources();
                            const existingGS = resources.lookup(PDFLib.PDFName.of('ExtGState'));
                            const gsDict = existingGS || pdfDoc.context.obj({});
                            if (!existingGS) {
                                resources.set(PDFLib.PDFName.of('ExtGState'), gsDict);
                            }
                            gsDict.set(PDFLib.PDFName.of(gsName), gsRef);
                            
                            let ops = 'q\n';
                            ops += `/${gsName} gs\n`;
                            
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
                                
                                // Create graphics state with opacity
                                const gsName = `GS${Date.now()}`;
                                const extGState = pdfDoc.context.obj({
                                    Type: 'ExtGState',
                                    CA: opacity,
                                    ca: opacity
                                });
                                const gsRef = pdfDoc.context.register(extGState);
                                const resources = page.node.Resources();
                                const existingGS = resources.lookup(PDFLib.PDFName.of('ExtGState'));
                                const gsDict = existingGS || pdfDoc.context.obj({});
                                if (!existingGS) {
                                    resources.set(PDFLib.PDFName.of('ExtGState'), gsDict);
                                }
                                gsDict.set(PDFLib.PDFName.of(gsName), gsRef);
                                
                                let ops = 'q\n'; // Save graphics state
                                ops += `/${gsName} gs\n`; // Apply graphics state with opacity
                                
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
                
                // NOTE: Rotations were already applied BEFORE pdf-lib, no need to send them again
                
                console.log('=== FINAL DATA BEING SENT TO BACKEND ===');
                console.log('Request URL:', saveUrl);
                console.log('Method:', 'POST');
                console.log('FormData Contents:');
                for (let [key, value] of formData.entries()) {
                    console.log(`  ${key}:`, value instanceof Blob ? `Blob (${value.size} bytes)` : value);
                }
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
                    setStatus('Saved! Refreshing...', 'ok');
                    setSelection(null);
                    
                    // Clear rotation state after successful save
                    Object.keys(pageRotations).forEach(key => delete pageRotations[key]);
                    
                    // Clear deleted pages state
                    pendingDeletedPages.length = 0;
                    
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
                    
                    // Reload the page to force backend to regenerate all cached files (clean PDF, extraction data)
                    // This prevents corruption issues when overlay editor is used after saving shapes
                    setTimeout(() => {
                        window.location.href = '{{ route("documents.edit", $document) }}';
                    }, 500);
                    
                    setSaveSpinner(true, 'Saved! Reloading...');
                    setStatus('Saved! Reloading page...', 'ok');
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
                            // Screenshot debugging disabled
                            // Get the first edited page number from the edits
                            const firstEditPageNumber = editsForVerification.length > 0 ? editsForVerification[0].page_number : 1;
                            
                            // console.log('=== TAKING SCREENSHOT AFTER SAVE ===');
                            // setStatus('Taking screenshot for debugging...', '');
                            // setSaveSpinner(true, 'Taking screenshot...');
                            
                            // const screenshotUrl = `{{ route('documents.takeScreenshot', $document) }}`;
                            
                            // try {
                            //     const screenshotResponse = await fetch(screenshotUrl, {
                            //         method: 'POST',
                            //         headers: {
                            //             'Content-Type': 'application/json',
                            //             'X-CSRF-TOKEN': csrfToken,
                            //         },
                            //         body: JSON.stringify({
                            //             page_number: firstEditPageNumber,
                            //             stage: 'after_overlay_save'
                            //         })
                            //     });
                            //     
                            //     if (screenshotResponse.ok) {
                            //         const screenshotData = await screenshotResponse.json();
                            //         console.log('Screenshot taken:', screenshotData);
                            //     } else {
                            //         console.error('Screenshot failed');
                            //     }
                            // } catch (screenshotError) {
                            //     console.error('Screenshot failed:', screenshotError);
                            // }
                            
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

            if (selectedFont) {
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
            }

            if (selectedWeight) {
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
            }

            if (selectedSize) {
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
            }

            if (selectedBold) {
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
            }

            if (selectedItalic) {
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
            }

            if (selectedUnderline) {
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
            }

            if (selectedColor) {
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
            }

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

            if (selectedBg) {
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
            }

            if (selectedAlign) {
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
            }

            if (selectedOpacity) {
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
            }

            if (selectedDelete) {
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
            }

            // ── Edit Text Banner Controls ──────────────────────────────────
            (function wireEditTextBanner() {
                const etbFont = document.getElementById('etb-font');
                const etbSize = document.getElementById('etb-size');
                const etbTextColor = document.getElementById('etb-text-color');
                const etbBgColor = document.getElementById('etb-bg-color');
                const etbOpacity = document.getElementById('etb-opacity');
                const etbBold = document.getElementById('etb-bold');
                const etbItalic = document.getElementById('etb-italic');
                const etbUnderline = document.getElementById('etb-underline');
                const etbAlign = document.getElementById('etb-align');
                const etbCopy = document.getElementById('etb-copy');
                const etbDelete = document.getElementById('etb-delete');
                
                // Helper: push current banner styles into active text-box-creator input (request #3)
                function syncToActiveBox() {
                    if (!activeEditor || !activeEditor.classList.contains('text-box-creator')) return;
                    const inp = activeEditor._tbcInput || activeEditor.querySelector('.tbc-input');
                    if (!inp) return;
                    if (etbFont) inp.style.fontFamily = fontMap[etbFont.value]?.css || 'inherit';
                    if (etbSize) inp.style.fontSize = Math.max(8, Math.min(144, parseInt(etbSize.value || '16', 10))) + 'px';
                    if (etbTextColor) inp.style.color = etbTextColor.value;
                    if (etbBold) inp.style.fontWeight = etbBold.classList.contains('active') ? '700' : 'normal';
                    if (etbItalic) inp.style.fontStyle = etbItalic.classList.contains('active') ? 'italic' : 'normal';
                    if (etbUnderline) inp.style.textDecoration = etbUnderline.classList.contains('active') ? 'underline' : 'none';
                    if (etbAlign) inp.style.textAlign = etbAlign.value;
                    if (etbOpacity) inp.style.opacity = etbOpacity.value;
                    if (etbBgColor) inp.style.backgroundColor = etbBgColor.value;
                }
                
                if (etbFont) etbFont.addEventListener('change', () => {
                    syncToActiveBox();
                    if (!selectedAnnotation || (selectedAnnotation.type !== 'text' && selectedAnnotation.type)) return;
                    selectedAnnotation.fontFamily = etbFont.value;
                    applyAnnotationStyle(selectedAnnotation);
                    persistAnnotations();
                    saveAnnotationToDatabase(selectedAnnotation);
                    if (selectedFont) selectedFont.value = etbFont.value;
                });
                
                if (etbSize) etbSize.addEventListener('change', () => {
                    syncToActiveBox();
                    if (!selectedAnnotation || (selectedAnnotation.type !== 'text' && selectedAnnotation.type)) return;
                    const sizePx = Math.max(8, Math.min(144, parseInt(etbSize.value || '16', 10)));
                    selectedAnnotation.fontSize = sizePx / currentScale;
                    applyAnnotationStyle(selectedAnnotation);
                    persistAnnotations();
                    saveAnnotationToDatabase(selectedAnnotation);
                    if (selectedSize) selectedSize.value = sizePx;
                });
                
                if (etbTextColor) etbTextColor.addEventListener('input', () => {
                    syncToActiveBox();
                    if (!selectedAnnotation || (selectedAnnotation.type !== 'text' && selectedAnnotation.type)) return;
                    selectedAnnotation.textColor = etbTextColor.value;
                    applyAnnotationStyle(selectedAnnotation);
                    persistAnnotations();
                    saveAnnotationToDatabase(selectedAnnotation);
                    if (selectedColor) selectedColor.value = etbTextColor.value;
                });
                
                if (etbBgColor) etbBgColor.addEventListener('input', () => {
                    syncToActiveBox();
                    if (!selectedAnnotation || (selectedAnnotation.type !== 'text' && selectedAnnotation.type)) return;
                    selectedAnnotation.backgroundColor = etbBgColor.value;
                    applyAnnotationStyle(selectedAnnotation);
                    persistAnnotations();
                    saveAnnotationToDatabase(selectedAnnotation);
                    if (selectedBg) selectedBg.value = etbBgColor.value;
                });
                
                if (etbOpacity) etbOpacity.addEventListener('change', () => {
                    syncToActiveBox();
                    if (!selectedAnnotation || (selectedAnnotation.type !== 'text' && selectedAnnotation.type)) return;
                    selectedAnnotation.opacity = parseFloat(etbOpacity.value || '1') || 1;
                    applyAnnotationStyle(selectedAnnotation);
                    persistAnnotations();
                    saveAnnotationToDatabase(selectedAnnotation);
                    if (selectedOpacity) selectedOpacity.value = etbOpacity.value;
                });
                
                if (etbBold) etbBold.addEventListener('click', () => {
                    etbBold.classList.toggle('active');
                    syncToActiveBox();
                    if (!selectedAnnotation || (selectedAnnotation.type !== 'text' && selectedAnnotation.type)) return;
                    selectedAnnotation.fontWeight = etbBold.classList.contains('active') ? '700' : 'normal';
                    applyAnnotationStyle(selectedAnnotation);
                    persistAnnotations();
                    saveAnnotationToDatabase(selectedAnnotation);
                    if (selectedBold) selectedBold.classList.toggle('active', selectedAnnotation.fontWeight === '700');
                    updateSelectionBar();
                });
                
                if (etbItalic) etbItalic.addEventListener('click', () => {
                    etbItalic.classList.toggle('active');
                    syncToActiveBox();
                    if (!selectedAnnotation || (selectedAnnotation.type !== 'text' && selectedAnnotation.type)) return;
                    selectedAnnotation.fontStyle = etbItalic.classList.contains('active') ? 'italic' : 'normal';
                    applyAnnotationStyle(selectedAnnotation);
                    persistAnnotations();
                    saveAnnotationToDatabase(selectedAnnotation);
                    if (selectedItalic) selectedItalic.classList.toggle('active', selectedAnnotation.fontStyle === 'italic');
                    updateSelectionBar();
                });
                
                if (etbUnderline) etbUnderline.addEventListener('click', () => {
                    etbUnderline.classList.toggle('active');
                    syncToActiveBox();
                    if (!selectedAnnotation || (selectedAnnotation.type !== 'text' && selectedAnnotation.type)) return;
                    selectedAnnotation.underline = etbUnderline.classList.contains('active');
                    applyAnnotationStyle(selectedAnnotation);
                    persistAnnotations();
                    saveAnnotationToDatabase(selectedAnnotation);
                    if (selectedUnderline) selectedUnderline.classList.toggle('active', selectedAnnotation.underline);
                    updateSelectionBar();
                });
                
                if (etbAlign) etbAlign.addEventListener('change', () => {
                    syncToActiveBox();
                    if (!selectedAnnotation || (selectedAnnotation.type !== 'text' && selectedAnnotation.type)) return;
                    selectedAnnotation.textAlign = etbAlign.value;
                    applyAnnotationStyle(selectedAnnotation);
                    persistAnnotations();
                    saveAnnotationToDatabase(selectedAnnotation);
                    if (selectedAlign) selectedAlign.value = etbAlign.value;
                });
                
                if (etbCopy) etbCopy.addEventListener('click', () => {
                    if (!selectedAnnotation || (selectedAnnotation.type !== 'text' && selectedAnnotation.type)) return;
                    const newAnnotation = {
                        ...JSON.parse(JSON.stringify(selectedAnnotation)),
                        id: generateAnnotationId(),
                        pdfX: selectedAnnotation.pdfX + 10 / currentScale,
                        pdfY: selectedAnnotation.pdfY - 10 / currentScale,
                    };
                    delete newAnnotation.element;
                    normalizeTextAnnotation(newAnnotation);
                    annotations.push(newAnnotation);
                    persistAnnotations();
                    saveAnnotationToDatabase(newAnnotation);
                    rerenderPdf();
                    setStatus('Text duplicated.', 'ok');
                });
                
                if (etbDelete) etbDelete.addEventListener('click', () => {
                    if (!selectedAnnotation) return;
                    const element = selectedAnnotation.element;
                    const idx = annotations.indexOf(selectedAnnotation);
                    if (idx >= 0) annotations.splice(idx, 1);
                    if (element && element.parentNode) element.parentNode.removeChild(element);
                    selectedAnnotation = null;
                    updateSelectionBar();
                    updateEditTextBanner();
                    persistAnnotations();
                    updateAnnotationsList();
                    setStatus('Text deleted.', 'ok');
                });
            })();

            if (insertX) {
                insertX.addEventListener('click', () => {
                    insertMode = insertMode === 'x' ? null : 'x';
                    insertCheckbox.classList.remove('pill-active');
                    insertX.classList.toggle('pill-active', insertMode === 'x');
                    toolMode = 'select';
                    updateModeButtons();
                    setStatus(insertMode ? 'Click on the PDF to place an X.' : 'Insert mode cleared.', 'ok');
                });
            }

            if (insertCheckbox) {
                insertCheckbox.addEventListener('click', () => {
                    insertMode = insertMode === 'checkbox' ? null : 'checkbox';
                    insertX.classList.remove('pill-active');
                    insertCheckbox.classList.toggle('pill-active', insertMode === 'checkbox');
                    toolMode = 'select';
                    updateModeButtons();
                    setStatus(insertMode ? 'Click on the PDF to place a checkbox.' : 'Insert mode cleared.', 'ok');
                });
            }

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
            const shapeStrokeWidthLabel = document.getElementById('shape-stroke-width-label');
            const shapeFillColorInput = document.getElementById('shape-fill-color');
            const shapeFillHexInput = document.getElementById('shape-fill-hex');
            const shapeFillTransparentInput = document.getElementById('shape-fill-transparent');
            const shapeOpacityInput = document.getElementById('shape-opacity');
            const shapeOpacityLabel = document.getElementById('shape-opacity-label');
            const gridlinesToggle = document.getElementById('settings-gridlines-toggle');
            const gridlinesOptions = document.getElementById('settings-gridlines-options');
            const gridlinesSpacingInput = document.getElementById('settings-gridlines-spacing');
            const gridlinesSpacingLabel = document.getElementById('settings-gridlines-spacing-label');
            const gridlinesColorInput = document.getElementById('settings-gridlines-color');
            const gridlinesOpacityInput = document.getElementById('settings-gridlines-opacity');
            const settingsGearBtn = document.getElementById('settings-gear-btn');
            const settingsPopover = document.getElementById('settings-popover');

            // Gear button toggle
            if (settingsGearBtn && settingsPopover) {
                settingsGearBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isOpen = settingsPopover.style.display !== 'none';
                    settingsPopover.style.display = isOpen ? 'none' : 'block';
                });
                // Close popover when clicking outside
                document.addEventListener('click', (e) => {
                    if (!settingsPopover.contains(e.target) && e.target !== settingsGearBtn && !settingsGearBtn.contains(e.target)) {
                        settingsPopover.style.display = 'none';
                    }
                });
                // Prevent popover clicks from closing it
                settingsPopover.addEventListener('click', (e) => {
                    e.stopPropagation();
                });
            }

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
                            ${['#000000', '#FF0000', '#0000FF', '#00A86B', '#FFD700', '#E0E0E0', '#FFFFFF'].map(c => {
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
                const colorPickerThumb = picker.querySelector('#color-picker-thumb');
                const hueSlider = picker.querySelector('#hue-slider');
                const hueThumb = picker.querySelector('#hue-thumb');
                
                let currentHue = 0; // Store current hue value (0-360)
                let currentSaturation = 1; // Store current saturation (0-1)
                let currentValue = 1; // Store current value/brightness (0-1)
                
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
                
                // Convert hex to HSV to get saturation and value for thumb positioning
                const hexToHsv = (hex) => {
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
                    
                    const s = max === 0 ? 0 : delta / max;
                    const v = max;
                    
                    return { h: h * 360, s, v };
                };
                
                // Initialize with current color
                const hsv = hexToHsv(currentColor);
                currentHue = hsv.h;
                currentSaturation = hsv.s;
                currentValue = hsv.v;
                
                updateColorGradient(currentHue);
                hueThumb.style.left = (currentHue / 360 * 100) + '%';
                
                // Position the color picker thumb based on saturation and value
                const rect = colorGradient.getBoundingClientRect();
                const thumbX = currentSaturation * rect.width;
                const thumbY = (1 - currentValue) * rect.height;
                colorPickerThumb.style.left = thumbX + 'px';
                colorPickerThumb.style.top = thumbY + 'px';
                
                // Color gradient interaction
                const updateColorFromGradient = (e) => {
                    const rect = colorGradient.getBoundingClientRect();
                    const x = Math.max(0, Math.min(e.clientX - rect.left, rect.width));
                    const y = Math.max(0, Math.min(e.clientY - rect.top, rect.height));
                    
                    currentSaturation = x / rect.width;
                    currentValue = 1 - (y / rect.height);
                    
                    // Update thumb position
                    colorPickerThumb.style.left = x + 'px';
                    colorPickerThumb.style.top = y + 'px';
                    
                    // Calculate color from HSV
                    const rgb = hsvToRgb(currentHue / 360, currentSaturation, currentValue);
                    currentColor = rgbToHex(rgb.r, rgb.g, rgb.b);
                    picker.querySelector('#hex-input').value = currentColor;
                    highlightSelectedSwatch();
                    updateShapePreview();
                };
                
                let isGradientDragging = false;
                colorGradient.addEventListener('mousedown', (e) => {
                    isGradientDragging = true;
                    updateColorFromGradient(e);
                    e.preventDefault();
                    e.stopPropagation();
                });
                
                document.addEventListener('mousemove', (e) => {
                    if (isGradientDragging) {
                        updateColorFromGradient(e);
                    }
                });
                
                document.addEventListener('mouseup', () => {
                    if (isGradientDragging) {
                        isGradientDragging = false;
                    }
                });
                
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
                        
                        // Update currentColor based on the active tab
                        currentColor = currentTab === 'fill' ? annotation.fillColor : annotation.strokeColor;
                        picker.querySelector('#hex-input').value = currentColor;
                        
                        // Update hue and gradient based on new color
                        const hsv = hexToHsv(currentColor);
                        currentHue = hsv.h;
                        currentSaturation = hsv.s;
                        currentValue = hsv.v;
                        
                        updateColorGradient(currentHue);
                        hueThumb.style.left = (currentHue / 360 * 100) + '%';
                        
                        // Position the color picker thumb
                        const rect = colorGradient.getBoundingClientRect();
                        const thumbX = currentSaturation * rect.width;
                        const thumbY = (1 - currentValue) * rect.height;
                        colorPickerThumb.style.left = thumbX + 'px';
                        colorPickerThumb.style.top = thumbY + 'px';
                        
                        highlightSelectedSwatch();
                    });
                });
                
                // Color swatches
                picker.querySelectorAll('.color-swatch').forEach(swatch => {
                    swatch.addEventListener('click', () => {
                        const color = swatch.dataset.color;
                        currentColor = color;
                        picker.querySelector('#hex-input').value = color;
                        
                        // Update hue, saturation, value, and gradient based on new color
                        const hsv = hexToHsv(currentColor);
                        currentHue = hsv.h;
                        currentSaturation = hsv.s;
                        currentValue = hsv.v;
                        
                        updateColorGradient(currentHue);
                        hueThumb.style.left = (currentHue / 360 * 100) + '%';
                        
                        // Position the color picker thumb
                        const rect = colorGradient.getBoundingClientRect();
                        const thumbX = currentSaturation * rect.width;
                        const thumbY = (1 - currentValue) * rect.height;
                        colorPickerThumb.style.left = thumbX + 'px';
                        colorPickerThumb.style.top = thumbY + 'px';
                        
                        highlightSelectedSwatch();
                        updateShapePreview();
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
                    shapeTypeButtons.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
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
                shapeStrokeWidthLabel.textContent = value + 'px';
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
                shapeOpacityLabel.textContent = value + '%';
                shapeOpacityValue = value / 100;
            });

            // ── Gridlines ──
            function renderGridlines() {
                // Remove any existing gridlines
                document.querySelectorAll('.gridlines-overlay').forEach(el => el.remove());
                if (!gridlinesEnabled) return;

                const pageWrappers = viewer.querySelectorAll('.page, .page-wrapper');
                pageWrappers.forEach(wrapper => {
                    const canvas = wrapper.querySelector('canvas');
                    if (!canvas) return;
                    const w = canvas.width;
                    const h = canvas.height;
                    const spacing = gridlinesSpacing * (currentScale || 1);
                    const color = gridlinesColor;
                    const opacity = gridlinesOpacity;

                    const svgNS = 'http://www.w3.org/2000/svg';
                    const svg = document.createElementNS(svgNS, 'svg');
                    svg.setAttribute('width', w);
                    svg.setAttribute('height', h);
                    svg.setAttribute('viewBox', `0 0 ${w} ${h}`);
                    svg.classList.add('gridlines-overlay');
                    svg.style.position = 'absolute';
                    svg.style.top = '0';
                    svg.style.left = '0';
                    svg.style.width = w + 'px';
                    svg.style.height = h + 'px';
                    svg.style.pointerEvents = 'none';
                    svg.style.zIndex = '5';

                    // Vertical lines
                    for (let x = spacing; x < w; x += spacing) {
                        const line = document.createElementNS(svgNS, 'line');
                        line.setAttribute('x1', x);
                        line.setAttribute('y1', 0);
                        line.setAttribute('x2', x);
                        line.setAttribute('y2', h);
                        line.setAttribute('stroke', color);
                        line.setAttribute('stroke-opacity', opacity);
                        line.setAttribute('stroke-width', '1');
                        line.setAttribute('stroke-dasharray', '4,4');
                        svg.appendChild(line);
                    }
                    // Horizontal lines
                    for (let y = spacing; y < h; y += spacing) {
                        const line = document.createElementNS(svgNS, 'line');
                        line.setAttribute('x1', 0);
                        line.setAttribute('y1', y);
                        line.setAttribute('x2', w);
                        line.setAttribute('y2', y);
                        line.setAttribute('stroke', color);
                        line.setAttribute('stroke-opacity', opacity);
                        line.setAttribute('stroke-width', '1');
                        line.setAttribute('stroke-dasharray', '4,4');
                        svg.appendChild(line);
                    }

                    wrapper.appendChild(svg);
                });
            }

            gridlinesToggle.addEventListener('change', () => {
                gridlinesEnabled = gridlinesToggle.checked;
                gridlinesOptions.style.display = gridlinesEnabled ? 'block' : 'none';
                renderGridlines();
            });
            gridlinesSpacingInput.addEventListener('input', () => {
                gridlinesSpacing = parseInt(gridlinesSpacingInput.value);
                gridlinesSpacingLabel.textContent = gridlinesSpacing + 'px';
                renderGridlines();
            });
            gridlinesColorInput.addEventListener('input', () => {
                gridlinesColor = gridlinesColorInput.value;
                renderGridlines();
            });
            gridlinesOpacityInput.addEventListener('input', () => {
                gridlinesOpacity = parseInt(gridlinesOpacityInput.value) / 100;
                renderGridlines();
            });

            // Close modal
            shapeClose.addEventListener('click', closeShapeModal);

            // Apply and start drawing
            shapeApply.addEventListener('click', () => {
                closeShapeModal();
                toolMode = 'shape';
                insertMode = null;
                if (insertX) insertX.classList.remove('pill-active');
                if (insertCheckbox) insertCheckbox.classList.remove('pill-active');
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
                // Just close the modal - all changes (add/delete/rotate) are tracked
                // and will be applied when the user clicks Save PDF
                organizePagesModal.classList.remove('active');
                
                // Show status message indicating changes are pending
                const hasAdditions = pendingNewPages.length > 0;
                const hasDeletions = pendingDeletedPages.length > 0;
                const hasRotations = Object.keys(pageRotations).some(k => pageRotations[k] !== 0);
                
                if (hasAdditions || hasDeletions || hasRotations) {
                    const changes = [];
                    if (hasAdditions) changes.push(`${pendingNewPages.length} page(s) to add`);
                    if (hasDeletions) changes.push(`${pendingDeletedPages.length} page(s) to delete`);
                    if (hasRotations) changes.push('page rotations');
                    setStatus(`Changes pending: ${changes.join(', ')}. Click Save PDF to apply.`, 'ok');
                } else {
                    setStatus('Organize complete. No changes pending.', 'ok');
                }
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
                        font_weight: editData.font_weight || null,  // Explicit weight (400, 700, etc.)
                        font_style: editData.font_style || null,    // Explicit style (normal, italic)
                        line_height: editData.line_height || null,   // Include line height
                        color: color,
                        rich_html: editData.rich_html || null        // Always send rich_html for per-word font info
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
                    let errorMessage;
                    try {
                        const errorJson = JSON.parse(errorText);
                        errorMessage = errorJson.error || errorText;
                    } catch (e) {
                        errorMessage = errorText;
                    }
                    throw new Error(errorMessage || `Overlay save failed (${saveResponse.status})`);
                }

                const saveResult = await saveResponse.json();

                if (!saveResult.success) {
                    throw new Error(saveResult.error || saveResult.message || 'Failed to save overlay edits: Unknown error');
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
                                font_weight: editData.font_weight || null,
                                font_style: editData.font_style || null,
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
                if (!selectedPageItem) return;
                
                // Count non-deleted pages (excluding pending pages that haven't been saved yet)
                const realPageItems = organizePagesGrid.querySelectorAll('.organize-page-item:not(.pending-page)');
                const visibleRealPages = realPageItems.length - pendingDeletedPages.length;
                
                // Check if this is a pending page (not yet saved to PDF)
                const isPendingPage = selectedPageItem.classList.contains('pending-page');
                
                if (!isPendingPage && visibleRealPages <= 1) {
                    alert('Cannot delete the last page of the document.');
                    return;
                }
                
                const confirmMsg = isPendingPage 
                    ? 'Remove this pending page?' 
                    : '⚠️ DELETE this page?\n\nThe page will be removed when you click Apply or Save PDF.';
                
                if (confirm(confirmMsg)) {
                    if (isPendingPage) {
                        // Remove pending page from tracking
                        const pendingId = selectedPageItem.dataset.pendingId;
                        const idx = pendingNewPages.findIndex(p => p.id === pendingId);
                        if (idx !== -1) {
                            pendingNewPages.splice(idx, 1);
                        }
                        // Also remove from main viewer and thumbnails
                        document.querySelectorAll(`[data-page-index="${pendingId}"]`).forEach(el => el.remove());
                    } else {
                        // Track real page for deletion
                        const pageNumber = parseInt(selectedPageItem.dataset.pageNumber);
                        const pageIndex = pageNumber - 1;
                        if (!pendingDeletedPages.includes(pageIndex)) {
                            pendingDeletedPages.push(pageIndex);
                        }
                        // Remove from main viewer
                        const pageWrapper = viewer.querySelector(`.page[data-page-index="${pageIndex}"]`);
                        if (pageWrapper) pageWrapper.remove();
                        // Remove from thumbnail sidebar
                        const thumbWrapper = pageList?.querySelector(`[data-page-index="${pageIndex}"]`);
                        if (thumbWrapper) thumbWrapper.remove();
                    }
                    
                    // Remove from organize grid
                    const index = Array.from(organizePagesGrid.children).indexOf(selectedPageItem);
                    if (index !== -1) {
                        organizePageOrder.splice(index, 1);
                    }
                    selectedPageItem.remove();
                    selectedPageItem = null;
                    updateOrganizePageNumbers();
                    updateOrganizeToolbarButtons();
                    
                    setStatus('Page marked for deletion. Click Apply or Save PDF to finalize.', 'ok');
                }
            });

            addPageBtn.addEventListener('click', async () => {
                try {
                    addPageBtn.disabled = true;
                    let insertAfterIndex = -1; // Default to beginning if nothing selected
                    let sizeReferenceIndex = 0;
                    
                    if (selectedPageItem) {
                        // Get the actual page number (1-based) from the dataset
                        const pageNumber = parseInt(selectedPageItem.dataset.pageNumber);
                        // Convert to 0-based index
                        insertAfterIndex = pageNumber - 1;
                        sizeReferenceIndex = pageNumber - 1;
                    } else {
                        // If no selection, add at the end
                        const pageItems = organizePagesGrid.querySelectorAll('.organize-page-item:not(.pending-page)');
                        insertAfterIndex = pageItems.length - 1;
                        sizeReferenceIndex = Math.max(0, pageItems.length - 1);
                    }

                    // Get reference page dimensions from pdfjsDocument
                    let pageWidth = 612; // Default Letter width
                    let pageHeight = 792; // Default Letter height
                    
                    if (pdfjsDocument && sizeReferenceIndex >= 0) {
                        try {
                            const refPage = await pdfjsDocument.getPage(sizeReferenceIndex + 1);
                            const viewport = refPage.getViewport({ scale: 1.0 });
                            pageWidth = viewport.width;
                            pageHeight = viewport.height;
                        } catch (e) {
                            console.warn('Could not get reference page size, using defaults');
                        }
                    }

                    // Calculate pending page index
                    const pendingIdx = pendingNewPages.length;
                    const pendingPageId = 'pending-' + pendingIdx;
                    
                    // Track this pending new page (will be added during save)
                    const pendingPageData = {
                        id: pendingPageId,
                        insertAfter: insertAfterIndex,
                        width: pageWidth,
                        height: pageHeight,
                        annotations: []
                    };
                    pendingNewPages.push(pendingPageData);
                    
                    // Create visual placeholder in organize grid
                    const newPageItem = document.createElement('div');
                    newPageItem.className = 'organize-page-item pending-page';
                    newPageItem.dataset.pageNumber = 'new';
                    newPageItem.dataset.pendingId = pendingPageId;
                    newPageItem.innerHTML = `
                        <div class="page-preview" style="background: white; display: flex; align-items: center; justify-content: center; border: 2px dashed #28a745; aspect-ratio: ${pageWidth}/${pageHeight};">
                            <span style="color: #28a745; font-size: 32px;">📄</span>
                        </div>
                        <div class="page-number" style="color: #28a745;">New</div>
                    `;
                    
                    // Insert after the selected page or at the end
                    if (selectedPageItem && selectedPageItem.nextSibling) {
                        organizePagesGrid.insertBefore(newPageItem, selectedPageItem.nextSibling);
                    } else if (selectedPageItem) {
                        organizePagesGrid.appendChild(newPageItem);
                    } else {
                        organizePagesGrid.appendChild(newPageItem);
                    }
                    
                    // Make it clickable to select
                    newPageItem.addEventListener('click', () => {
                        selectPageItem(newPageItem);
                    });
                    
                    // Select the new page
                    selectPageItem(newPageItem);
                    
                    // Also add to main viewer and thumbnail sidebar
                    // (Re-use the same logic as page_controller)
                    if (pdfjsDocument) {
                        try {
                            const refPage = await pdfjsDocument.getPage(Math.max(1, sizeReferenceIndex + 1));
                            const scaledViewport = refPage.getViewport({ scale: currentScale });
                            
                            // Add thumbnail placeholder
                            const thumbnailContainer = pageList;
                            if (thumbnailContainer) {
                                const existingThumbnails = thumbnailContainer.querySelectorAll('[data-page-index]');
                                const insertAfterElement = insertAfterIndex >= 0 ? existingThumbnails[insertAfterIndex] : null;
                                
                                const thumbWrapper = document.createElement('div');
                                thumbWrapper.className = 'thumbnail-wrapper pending-page';
                                thumbWrapper.dataset.pageIndex = pendingPageId;
                                thumbWrapper.innerHTML = `
                                    <div class="thumbnail-label">New Page</div>
                                    <div class="thumbnail-canvas-wrapper" style="background: white; display: flex; align-items: center; justify-content: center; min-height: 150px; border: 2px dashed #28a745;">
                                        <span style="color: #28a745; font-size: 24px;">📄</span>
                                    </div>
                                `;
                                
                                if (insertAfterElement && insertAfterElement.nextSibling) {
                                    thumbnailContainer.insertBefore(thumbWrapper, insertAfterElement.nextSibling);
                                } else if (insertAfterElement) {
                                    thumbnailContainer.appendChild(thumbWrapper);
                                } else {
                                    thumbnailContainer.appendChild(thumbWrapper);
                                }
                            }
                            
                            // Add blank page to main viewer
                            const mainViewer = viewer;
                            const existingPages = mainViewer.querySelectorAll('.page[data-page-index]');
                            const insertAfterMainPage = insertAfterIndex >= 0 ? existingPages[insertAfterIndex] : null;
                            
                            const blankPageEl = document.createElement('div');
                            blankPageEl.className = 'page pending-page';
                            blankPageEl.dataset.pageIndex = pendingPageId;
                            blankPageEl.style.cssText = `
                                position: relative;
                                width: ${scaledViewport.width}px;
                                height: ${scaledViewport.height}px;
                                margin: 10px auto;
                                box-shadow: 0 2px 8px rgba(0,0,0,0.3);
                            `;
                            
                            const blankCanvas = document.createElement('canvas');
                            blankCanvas.width = scaledViewport.width;
                            blankCanvas.height = scaledViewport.height;
                            blankCanvas.style.cssText = 'position: absolute; top: 0; left: 0; z-index: 1;';
                            const ctx = blankCanvas.getContext('2d');
                            ctx.fillStyle = 'white';
                            ctx.fillRect(0, 0, blankCanvas.width, blankCanvas.height);
                            ctx.strokeStyle = '#28a745';
                            ctx.lineWidth = 2;
                            ctx.setLineDash([10, 5]);
                            ctx.strokeRect(10, 10, blankCanvas.width - 20, blankCanvas.height - 20);
                            ctx.setLineDash([]);
                            ctx.fillStyle = 'rgba(40, 167, 69, 0.15)';
                            ctx.font = 'bold 48px Arial';
                            ctx.textAlign = 'center';
                            ctx.textBaseline = 'middle';
                            ctx.fillText('New Page', blankCanvas.width / 2, blankCanvas.height / 2);
                            blankPageEl.appendChild(blankCanvas);
                            
                            // Create overlay for interactions
                            const overlay = document.createElement('div');
                            overlay.className = 'overlay pdf-overlay';
                            overlay.style.cssText = `
                                position: absolute;
                                top: 0;
                                left: 0;
                                width: 100%;
                                height: 100%;
                                cursor: crosshair;
                                pointer-events: auto;
                                z-index: 10;
                            `;
                            blankPageEl.appendChild(overlay);
                            
                            // Create annotation layer
                            const annotationLayer = document.createElement('div');
                            annotationLayer.className = 'annotation-layer';
                            annotationLayer.style.cssText = `
                                position: absolute;
                                top: 0;
                                left: 0;
                                width: 100%;
                                height: 100%;
                                pointer-events: none;
                                z-index: 5;
                            `;
                            blankPageEl.appendChild(annotationLayer);
                            
                            // Store references
                            pendingPageData.canvas = blankCanvas;
                            pendingPageData.element = blankPageEl;
                            pendingPageData.scale = currentScale;
                            pendingPageData.overlay = overlay;
                            
                            const pageInfo = {
                                scale: currentScale,
                                canvasHeight: blankCanvas.height,
                            };
                            
                            // Click handler for deselect when not in text mode
                            overlay.addEventListener('click', (event) => {
                                if (event.target !== overlay) return;
                                if (toolMode !== 'text') {
                                    setSelection(null);
                                    removeActiveEditor();
                                }
                            });
                            
                            // Drag-to-create text box for organize pending pages
                            (function setupOrgTextDrag(ov, pid, cv, el, pi) {
                                let dragState = null;
                                ov.addEventListener('pointerdown', (e) => {
                                    if (toolMode !== 'text') return;
                                    if (e.target !== ov) return;
                                    e.preventDefault();
                                    ov.setPointerCapture(e.pointerId);
                                    const rect = ov.getBoundingClientRect();
                                    const sx = e.clientX - rect.left;
                                    const sy = e.clientY - rect.top;
                                    const sel = document.createElement('div');
                                    sel.className = 'text-drag-selection';
                                    sel.style.left = sx + 'px';
                                    sel.style.top = sy + 'px';
                                    sel.style.width = '0';
                                    sel.style.height = '0';
                                    ov.appendChild(sel);
                                    dragState = { startX: sx, startY: sy, sel, rect, moved: false };
                                });
                                ov.addEventListener('pointermove', (e) => {
                                    if (!dragState) return;
                                    const cx = e.clientX - dragState.rect.left;
                                    const cy = e.clientY - dragState.rect.top;
                                    const lx = Math.min(dragState.startX, cx);
                                    const ly = Math.min(dragState.startY, cy);
                                    const w = Math.abs(cx - dragState.startX);
                                    const h = Math.abs(cy - dragState.startY);
                                    dragState.sel.style.left = lx + 'px';
                                    dragState.sel.style.top = ly + 'px';
                                    dragState.sel.style.width = w + 'px';
                                    dragState.sel.style.height = h + 'px';
                                    if (w > 5 || h > 5) dragState.moved = true;
                                });
                                ov.addEventListener('pointerup', (e) => {
                                    if (!dragState) return;
                                    const ds = dragState;
                                    dragState = null;
                                    const cx = e.clientX - ds.rect.left;
                                    const cy = e.clientY - ds.rect.top;
                                    const bx = Math.min(ds.startX, cx);
                                    const by = Math.min(ds.startY, cy);
                                    const bw = Math.abs(cx - ds.startX);
                                    const bh = Math.abs(cy - ds.startY);
                                    ds.sel.remove();
                                    const opts = readBannerOpts();
                                    if (ds.moved && bw > 20 && bh > 10) {
                                        createTextBoxCreator(ov, bx, by, pid, cv, el, pi, opts, bw, bh);
                                    } else {
                                        createTextBoxCreator(ov, bx, by, pid, cv, el, pi, opts);
                                    }
                                });
                            })(overlay, pendingPageId, blankCanvas, blankPageEl, pageInfo);
                            
                            // Add shape drawing handlers for pending page
                            let organizeDrawingShape = null;
                            
                            overlay.addEventListener('pointerdown', (event) => {
                                if (toolMode !== 'shape') return;
                                // Don't start drawing if clicking on an existing annotation
                                if (event.target !== overlay && event.target.closest('.annotation')) {
                                    return;
                                }
                                event.preventDefault();
                                overlay.setPointerCapture(event.pointerId);
                                
                                const rect = overlay.getBoundingClientRect();
                                const startX = event.clientX - rect.left;
                                const startY = event.clientY - rect.top;
                                
                                const shapeEl = document.createElement('div');
                                shapeEl.className = 'drawing-shape-preview';
                                shapeEl.style.cssText = `
                                    position: absolute;
                                    left: ${startX}px;
                                    top: ${startY}px;
                                    width: 0;
                                    height: 0;
                                    border: 2px dashed ${shapeStroke};
                                    background: ${shapeFillTransparentState ? 'transparent' : shapeFill + '80'};
                                    opacity: 0.7;
                                    pointer-events: none;
                                    z-index: 1000;
                                    box-sizing: border-box;
                                    ${shapeType === 'circle' || shapeType === 'ellipse' ? 'border-radius: 50%;' : ''}
                                `;
                                overlay.appendChild(shapeEl);
                                
                                organizeDrawingShape = {
                                    element: shapeEl,
                                    startX,
                                    startY
                                };
                            });
                            
                            overlay.addEventListener('pointermove', (event) => {
                                if (!organizeDrawingShape || toolMode !== 'shape') return;
                                
                                const rect = overlay.getBoundingClientRect();
                                const currentX = event.clientX - rect.left;
                                const currentY = event.clientY - rect.top;
                                
                                const left = Math.min(currentX, organizeDrawingShape.startX);
                                const top = Math.min(currentY, organizeDrawingShape.startY);
                                const width = Math.max(1, Math.abs(currentX - organizeDrawingShape.startX));
                                const height = Math.max(1, Math.abs(currentY - organizeDrawingShape.startY));
                                
                                organizeDrawingShape.element.style.left = left + 'px';
                                organizeDrawingShape.element.style.top = top + 'px';
                                organizeDrawingShape.element.style.width = width + 'px';
                                organizeDrawingShape.element.style.height = height + 'px';
                            });
                            
                            overlay.addEventListener('pointerup', (event) => {
                                if (!organizeDrawingShape || toolMode !== 'shape') return;
                                
                                const shapeRect = organizeDrawingShape.element.getBoundingClientRect();
                                const overlayRect = overlay.getBoundingClientRect();
                                const width = shapeRect.width;
                                const height = shapeRect.height;
                                
                                if (width < 6 || height < 6) {
                                    organizeDrawingShape.element.remove();
                                    organizeDrawingShape = null;
                                    return;
                                }
                                
                                const left = shapeRect.left - overlayRect.left;
                                const top = shapeRect.top - overlayRect.top;
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
                                    pageIndex: pendingPageId,
                                    pdfX,
                                    pdfY,
                                    pdfWidth,
                                    pdfHeight
                                };
                                
                                annotations.push(annotation);
                                persistAnnotations();
                                updateAnnotationsList();
                                addAnnotationElement(blankPageEl, annotation, pageInfo);
                                organizeDrawingShape.element.remove();
                                organizeDrawingShape = null;
                                setSelection(annotation);
                                setStatus('Shape added to new page. Click Save to keep changes.', 'ok');
                            });
                            
                            // Insert into main viewer
                            if (insertAfterMainPage && insertAfterMainPage.nextSibling) {
                                mainViewer.insertBefore(blankPageEl, insertAfterMainPage.nextSibling);
                            } else if (insertAfterMainPage) {
                                mainViewer.appendChild(blankPageEl);
                            } else {
                                mainViewer.appendChild(blankPageEl);
                            }
                        } catch (e) {
                            console.warn('Could not add page to main viewer:', e);
                        }
                    }
                    
                    updateOrganizePageNumbers();
                    setStatus('Blank page added. Click Apply or Save PDF to finalize.', 'ok');
                } catch (error) {
                    console.error('Error adding blank page:', error);
                    setStatus('Error adding page: ' + error.message, 'err');
                } finally {
                    addPageBtn.disabled = false;
                }
            });

            rotatePageBtn.addEventListener('click', async () => {
                if (!selectedPageItem) return;
                
                // Check if this is a pending page (can't rotate pending pages)
                if (selectedPageItem.classList.contains('pending-page')) {
                    setStatus('Cannot rotate a pending page. Save first to rotate.', 'err');
                    return;
                }
                
                try {
                    rotatePageBtn.disabled = true;
                    const pageNumber = parseInt(selectedPageItem.dataset.pageNumber);
                    const pageIndex = pageNumber - 1;
                    
                    // Update rotation state (90 degrees clockwise each click)
                    pageRotations[pageIndex] = (pageRotations[pageIndex] || 0) + 90;
                    if (pageRotations[pageIndex] >= 360) {
                        pageRotations[pageIndex] = 0;
                    }
                    
                    console.log(`[ROTATE] Page ${pageNumber} rotation set to ${pageRotations[pageIndex]}°`);
                    
                    // Re-render the page preview in organize modal with rotation
                    if (pdfjsDocument) {
                        const page = await pdfjsDocument.getPage(pageNumber);
                        const existingRotation = page.rotate || 0;
                        const totalRotation = existingRotation + pageRotations[pageIndex];
                        
                        // Update the organize modal thumbnail
                        const previewDiv = selectedPageItem.querySelector('.page-preview');
                        if (previewDiv) {
                            const canvas = previewDiv.querySelector('canvas');
                            if (canvas) {
                                const thumbWidth = 150;
                                const viewport = page.getViewport({ scale: thumbWidth / page.getViewport({ scale: 1.0 }).width, rotation: totalRotation });
                                canvas.width = viewport.width;
                                canvas.height = viewport.height;
                                const ctx = canvas.getContext('2d');
                                await page.render({ canvasContext: ctx, viewport }).promise;
                            }
                        }
                        
                        // Also update the main viewer page
                        const pageWrapper = viewer.querySelector(`.page[data-page-index="${pageIndex}"]`);
                        if (pageWrapper) {
                            const mainCanvas = pageWrapper.querySelector('canvas');
                            if (mainCanvas) {
                                const viewport = page.getViewport({ scale: currentScale, rotation: totalRotation });
                                mainCanvas.width = viewport.width;
                                mainCanvas.height = viewport.height;
                                const ctx = mainCanvas.getContext('2d');
                                await page.render({ canvasContext: ctx, viewport }).promise;
                                pageWrapper.style.width = viewport.width + 'px';
                                pageWrapper.style.height = viewport.height + 'px';
                            }
                        }
                        
                        // Update thumbnail in sidebar
                        const thumbEl = pageList?.querySelector(`[data-page-index="${pageIndex}"] canvas`);
                        if (thumbEl) {
                            const thumbWidth = 240;
                            const thumbViewport = page.getViewport({ scale: thumbWidth / page.getViewport({ scale: 1.0 }).width, rotation: totalRotation });
                            thumbEl.width = thumbViewport.width;
                            thumbEl.height = thumbViewport.height;
                            const thumbCtx = thumbEl.getContext('2d');
                            thumbCtx.imageSmoothingEnabled = true;
                            thumbCtx.imageSmoothingQuality = 'high';
                            await page.render({ canvasContext: thumbCtx, viewport: thumbViewport }).promise;
                        }
                    }
                    
                    setStatus(`Page ${pageNumber} rotated ${pageRotations[pageIndex]}°. Click Apply or Save PDF to finalize.`, 'ok');
                } catch (error) {
                    console.error('Error rotating page:', error);
                    setStatus('Error rotating page: ' + error.message, 'err');
                } finally {
                    rotatePageBtn.disabled = false;
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
                if (modeText) modeText.classList.toggle('active', toolMode === 'text');
                if (modeEditText) modeEditText.classList.toggle('active', toolMode === 'edit-text');
                if (modeSign) modeSign.classList.toggle('active', toolMode === 'sign');
                if (modeShape) modeShape.classList.toggle('active', toolMode === 'shape');
                if (modeOverlay) {
                    modeOverlay.checked = overlayEditorActive;
                }
                if (modeOverlayToggle) {
                    modeOverlayToggle.checked = overlayEditorActive;
                    modeOverlayToggle.classList.toggle('active', overlayEditorActive);
                }
                updateTextLayerVisibility();
            };

            if (modeText) {
                modeText.addEventListener('click', () => {
                    exitOverlayEditorForTool();
                    toolMode = 'text';
                    insertMode = null;
                    if (insertX) insertX.classList.remove('pill-active');
                    if (insertCheckbox) insertCheckbox.classList.remove('pill-active');
                    closeTextEditPopup();
                    updateModeButtons();
                    updateEditTextBanner();
                    setStatus('Add Text mode active. Click on the PDF to add new text.', 'ok');
                });
            }

            if (modeEditText) {
                modeEditText.addEventListener('click', () => {
                    // Switch to extracted text tab
                    const extractedTextTab = document.getElementById('extracted-text-tab');
                    extractedTextTab.click();
                });
            }

            // Close popup when clicking outside
            document.addEventListener('click', (e) => {
                const popup = document.querySelector('.text-edit-popup');
                if (popup && !popup.contains(e.target) && !e.target.classList.contains('pdf-text')) {
                    closeTextEditPopup();
                }
            });

            function updateEditTextBanner() {
                const banner = document.getElementById('edit-text-banner');
                if (!banner) return;
                
                // Show banner when: text mode is active OR a text annotation is selected
                const isTextMode = toolMode === 'text';
                const hasTextAnnotation = selectedAnnotation && (selectedAnnotation.type === 'text' || !selectedAnnotation.type);
                
                if (isTextMode || hasTextAnnotation) {
                    banner.classList.add('visible');
                    // Populate controls from selected annotation
                    if (hasTextAnnotation) {
                        populateEditTextBanner(selectedAnnotation);
                    } else {
                        // Default values for new text placement
                        populateEditTextBannerDefaults();
                    }
                } else {
                    banner.classList.remove('visible');
                }
            }
            
            function populateEditTextBanner(annotation) {
                const font = document.getElementById('etb-font');
                const size = document.getElementById('etb-size');
                const textColor = document.getElementById('etb-text-color');
                const bgColor = document.getElementById('etb-bg-color');
                const opacity = document.getElementById('etb-opacity');
                const bold = document.getElementById('etb-bold');
                const italic = document.getElementById('etb-italic');
                const underline = document.getElementById('etb-underline');
                const align = document.getElementById('etb-align');
                
                if (font) font.value = annotation.fontFamily || 'Helvetica';
                if (size) size.value = Math.round(annotation.fontSize * currentScale) || 16;
                if (textColor) textColor.value = annotation.textColor || '#111111';
                if (bgColor) {
                    const bg = annotation.backgroundColor || 'transparent';
                    bgColor.value = bg === 'transparent' ? '#ffffff' : bg;
                }
                if (opacity) {
                    const opVal = String(annotation.opacity ?? 1);
                    if ([...opacity.options].some(o => o.value === opVal)) {
                        opacity.value = opVal;
                    } else {
                        opacity.value = '1';
                    }
                }
                if (bold) bold.classList.toggle('active', annotation.fontWeight === '700' || annotation.fontWeight === 'bold');
                if (italic) italic.classList.toggle('active', annotation.fontStyle === 'italic');
                if (underline) underline.classList.toggle('active', Boolean(annotation.underline));
                if (align) align.value = annotation.textAlign || 'left';
            }
            
            function populateEditTextBannerDefaults() {
                const font = document.getElementById('etb-font');
                const size = document.getElementById('etb-size');
                const textColor = document.getElementById('etb-text-color');
                const bgColor = document.getElementById('etb-bg-color');
                const opacity = document.getElementById('etb-opacity');
                const bold = document.getElementById('etb-bold');
                const italic = document.getElementById('etb-italic');
                const underline = document.getElementById('etb-underline');
                const align = document.getElementById('etb-align');
                
                if (font) font.value = defaultTextFont;
                if (size) size.value = defaultTextSize;
                if (textColor) textColor.value = '#111111';
                if (bgColor) bgColor.value = '#ffffff';
                if (opacity) opacity.value = '1';
                if (bold) bold.classList.remove('active');
                if (italic) italic.classList.remove('active');
                if (underline) underline.classList.remove('active');
                if (align) align.value = 'left';
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

            if (modeSign) {
                modeSign.addEventListener('click', () => {
                    toolMode = 'sign';
                    insertMode = null;
                    if (insertX) insertX.classList.remove('pill-active');
                    if (insertCheckbox) insertCheckbox.classList.remove('pill-active');
                    updateModeButtons();
                    updateEditTextBanner();
                    openSignatureModal();
                });
            }

            if (modeShape) {
                modeShape.addEventListener('click', () => {
                    // Open shape settings modal
                    openShapeModal();
                });
            }

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

            const overlayToggleHandler = async (checked) => {
                if (!checked) {
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
                    // Reset basePdfUrl to original PDF (not clean PDF)
                    basePdfUrl = pdfUrl;
                    if (overlayExtractionData) {
                        renderPdfWithOverlay(true).then(() => {
                            if (typeof renderGridlines === 'function') renderGridlines();
                        });
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
                        if (typeof renderGridlines === 'function') renderGridlines();
                        setStatus('Overlay editor active. Edit text positions and content.', 'ok');
                        return;
                    }
                    // First, ensure clean PDF is created by calling the overlay editor endpoint
                    // Add cache-busting parameter to force regeneration after PDF changes
                    const prepareResponse = await fetch('{{ route("documents.prepareOverlay", $document) }}?v=' + Date.now(), { 
                        method: 'POST', 
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } 
                    });
                    if (!prepareResponse.ok) {
                        const errorText = await prepareResponse.text();
                        let errorData;
                        try {
                            errorData = JSON.parse(errorText);
                        } catch (e) {
                            errorData = { error: errorText };
                        }
                        console.error('Prepare overlay response:', errorData);
                        
                        // Provide helpful error message for corrupted PDFs
                        if (errorData.error && errorData.error.includes('Failed to prepare PDF')) {
                            throw new Error('The PDF file appears to be corrupted. This can happen after saving annotations. Please try refreshing the page. If the issue persists, you may need to re-upload the original PDF file.');
                        }
                        throw new Error('Failed to prepare clean PDF: ' + prepareResponse.statusText);
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
                    // Re-apply gridlines after overlay render
                    if (typeof renderGridlines === 'function') renderGridlines();
                    
                    setStatus('Overlay editor active. Edit text positions and content.', 'ok');
                } catch (error) {
                    console.error('Error loading overlay editor:', error);
                    let errorMessage = error.message;
                    if (error.message && error.message.includes('Page dictionary')) {
                        errorMessage = 'The PDF structure was modified and needs to be regenerated. Please refresh the page and try again. If the issue persists, the PDF may need to be re-uploaded.';
                    }
                    setStatus('Error loading overlay editor: ' + errorMessage, 'err');
                    cleanupOverlayPdf();  // Free memory on error
                    overlayEditorActive = false;
                    overlayLoadToken++;
                    if (saveOverlayBtn) {
                        saveOverlayBtn.style.display = 'none';
                    }
                    if (modeOverlay) {
                        modeOverlay.checked = false;
                    }
                    if (modeOverlayToggle) {
                        modeOverlayToggle.checked = false;
                    }
                    viewer.classList.add('overlay-hidden');
                    basePdfUrl = pdfUrl;
                    rerenderPdf();
                }
            };
            
            // Attach event listeners for overlay toggle
            if (modeOverlay) {
                modeOverlay.addEventListener('change', async () => {
                    await overlayToggleHandler(modeOverlay.checked);
                });
            }
            if (modeOverlayToggle) {
                modeOverlayToggle.addEventListener('change', async () => {
                    await overlayToggleHandler(modeOverlayToggle.checked);
                });
            }

            async function renderPdfWithOverlay(force = false) {
                if (!overlayEditorActive && !force) {
                    return;
                }
                try {
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
                } catch (error) {
                    console.error('Error rendering PDF with overlay:', error);
                    throw error; // Re-throw to be caught by overlay toggle handler
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
                        overlay.querySelectorAll('.overlay-field.active').forEach(f => f.classList.remove('active'));
                        updateSelectionBar();
                        if (toolMode !== 'text') {
                            setSelection(null);
                            removeActiveEditor();
                        }
                    }
                });
                
                // Define pageInfo before using it in IIFEs
                const pageInfo = {
                    scale: currentScale,
                    canvasHeight: canvas.height,
                };
                
                // Drag-to-create text box for load-more pages
                (function setupLMTextDrag(ov, pn, cv, wr, pi) {
                    let dragState = null;
                    ov.addEventListener('pointerdown', (e) => {
                        if (toolMode !== 'text') return;
                        if (e.target !== ov) return;
                        e.preventDefault();
                        ov.setPointerCapture(e.pointerId);
                        const rect = ov.getBoundingClientRect();
                        const sx = e.clientX - rect.left;
                        const sy = e.clientY - rect.top;
                        const sel = document.createElement('div');
                        sel.className = 'text-drag-selection';
                        sel.style.left = sx + 'px';
                        sel.style.top = sy + 'px';
                        sel.style.width = '0';
                        sel.style.height = '0';
                        ov.appendChild(sel);
                        dragState = { startX: sx, startY: sy, sel, rect, moved: false };
                    });
                    ov.addEventListener('pointermove', (e) => {
                        if (!dragState) return;
                        const cx = e.clientX - dragState.rect.left;
                        const cy = e.clientY - dragState.rect.top;
                        const lx = Math.min(dragState.startX, cx);
                        const ly = Math.min(dragState.startY, cy);
                        const w = Math.abs(cx - dragState.startX);
                        const h = Math.abs(cy - dragState.startY);
                        dragState.sel.style.left = lx + 'px';
                        dragState.sel.style.top = ly + 'px';
                        dragState.sel.style.width = w + 'px';
                        dragState.sel.style.height = h + 'px';
                        if (w > 5 || h > 5) dragState.moved = true;
                    });
                    ov.addEventListener('pointerup', (e) => {
                        if (!dragState) return;
                        const ds = dragState;
                        dragState = null;
                        const cx = e.clientX - ds.rect.left;
                        const cy = e.clientY - ds.rect.top;
                        const bx = Math.min(ds.startX, cx);
                        const by = Math.min(ds.startY, cy);
                        const bw = Math.abs(cx - ds.startX);
                        const bh = Math.abs(cy - ds.startY);
                        ds.sel.remove();
                        const opts = readBannerOpts();
                        if (ds.moved && bw > 20 && bh > 10) {
                            createTextBoxCreator(ov, bx, by, pn - 1, cv, wr, pi, opts, bw, bh);
                        } else {
                            createTextBoxCreator(ov, bx, by, pn - 1, cv, wr, pi, opts);
                        }
                    });
                })(overlay, pageNumber, canvas, wrapper, pageInfo);
                
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

                // Normalize PDF font name by stripping subset prefixes and weight suffixes
                // e.g. "PdbpbbLato-Regular" → "Lato", "MontserratThin_700wght" → "Montserrat"
                const normalizePdfFontName = (fontName) => {
                    if (!fontName) return '';
                    let cleaned = fontName;

                    // Remove PDF font prefixes with '+' separator (e.g., "ABCDEF+FontName")
                    if (cleaned.includes('+')) {
                        const parts = cleaned.split('+', 2);
                        if (parts[0].length === 6) {
                            cleaned = parts[1];
                        }
                    }

                    // Strip 6-character prefix (upper or lowercase) embedded without separator
                    // e.g., "PdbpbbLato-Regular" → "Lato-Regular", "ABCDEF+Arial" already handled above
                    if (/^[A-Za-z]{6}[A-Z]/.test(cleaned) && cleaned.length > 7) {
                        const withoutPrefix = cleaned.substring(6);
                        // Only strip if remainder starts with uppercase+lowercase (looks like font name)
                        if (/^[A-Z][a-z]/.test(withoutPrefix)) {
                            cleaned = withoutPrefix;
                        }
                    }

                    // Split off style/weight suffixes (e.g., "-Regular", "_700wght")
                    const basePart = cleaned.split(/[-_,]/)[0] || cleaned;

                    // Remove weight variant suffixes fused with family name
                    // e.g., "MontserratThin" → "Montserrat", "LatoBlack" → "Lato"
                    const weightSuffixes = ['Thin', 'Hairline', 'ExtraLight', 'UltraLight', 'Light',
                        'Regular', 'Medium', 'SemiBold', 'DemiBold', 'Bold', 'ExtraBold',
                        'UltraBold', 'Black', 'Heavy'];
                    let family = basePart;
                    for (const suffix of weightSuffixes) {
                        if (family.endsWith(suffix) && family.length > suffix.length) {
                            family = family.substring(0, family.length - suffix.length);
                            break;
                        }
                    }

                    return family;
                };

                const getCssFontFamily = (fontName) => {
                    if (!fontName) return 'sans-serif';

                    const family = normalizePdfFontName(fontName);

                    // Common PDF font mappings to system/web fonts
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
                        'TrebuchetMS': 'Trebuchet MS, sans-serif',
                        'Lato': 'Lato, sans-serif',
                        'Montserrat': 'Montserrat, sans-serif',
                        'Roboto': 'Roboto, sans-serif',
                        'OpenSans': 'Open Sans, sans-serif',
                        'Poppins': 'Poppins, sans-serif',
                        'Raleway': 'Raleway, sans-serif',
                        'Nunito': 'Nunito, sans-serif',
                        'Inter': 'Inter, sans-serif',
                        'Oswald': 'Oswald, sans-serif',
                        'SourceSansPro': 'Source Sans Pro, sans-serif',
                        'PlayfairDisplay': 'Playfair Display, serif',
                        'Merriweather': 'Merriweather, serif',
                    };

                    // Check if we have a direct mapping
                    for (const [key, value] of Object.entries(fontMappings)) {
                        if (family.toLowerCase() === key.toLowerCase()) {
                            return value;
                        }
                    }

                    // Return with fallback
                    if (family.toLowerCase().includes('serif') && !family.toLowerCase().includes('sans')) {
                        return `"${family}", serif`;
                    } else if (family.toLowerCase().includes('mono') || family.toLowerCase().includes('courier')) {
                        return `"${family}", monospace`;
                    } else {
                        return `"${family}", Arial, sans-serif`;
                    }
                };

                const loadOverlayFonts = (words) => {
                    const families = new Set();
                    words.forEach((word) => {
                        if (!word.font) return;
                        const family = normalizePdfFontName(word.font);
                        if (family) {
                            families.add(family);
                        }
                    });

                    families.forEach((family) => {
                        if (overlayLoadedFonts.has(family)) return;
                        overlayLoadedFonts.add(family);
                        const link = document.createElement('link');
                        link.rel = 'stylesheet';
                        link.href = `https://fonts.googleapis.com/css2?family=${encodeURIComponent(family)}:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap`;
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
                    pageData.blocks.forEach((block) => {
                        if (!block.font) return;
                        const family = normalizePdfFontName(block.font);
                        if (family && !overlayLoadedFonts.has(family)) {
                            overlayLoadedFonts.add(family);
                            const link = document.createElement('link');
                            link.rel = 'stylesheet';
                            link.href = `https://fonts.googleapis.com/css2?family=${encodeURIComponent(family)}:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap`;
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
                    
                    // PRIORITY: Check for explicit numeric weight patterns first
                    // e.g., "_700wght", "_100wght", "-700", "_400"
                    // This MUST come before keyword checks to handle names like
                    // "MontserratThin_700wght" where 'thin' is the family, not the weight.
                    const weightMatch = lower.match(/[_-](\d{3})w?g?h?t?/);
                    if (weightMatch) {
                        const w = parseInt(weightMatch[1], 10);
                        if (w >= 100 && w <= 900) return String(w);
                    }
                    
                    // Fallback: Check for specific weight names in order of specificity
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
                    
                    // Include font-family and font-weight on wrapper so Python can use them
                    // as fallback when individual spans lose their styling (e.g., after editing)
                    const wrapperStyle = `position:relative;width:100%;height:100%;font-family:${fontFamily};font-weight:${fontWeight};font-style:${fontStyle};font-size:${fontSizePx};${lineHeightPx ? `line-height:${lineHeightPx};` : ''}color:${textColor};`;
                    
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

                const computeOverlayPaddingPdf = (block) => {
                    const fontSize = Number(block?.font_size) || 12;
                    const basePad = fontSize * 0.35;
                    // Clamp to keep boxes visible but not oversized
                    return Math.max(1.5, Math.min(basePad, 10));
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

                    // ── Gap-based block splitting ──────────────────────────────
                    // PyMuPDF merges entire table rows into one block. We detect
                    // large horizontal gaps between words on the same line and
                    // split them into separate visual blocks so each table cell
                    // gets its own bounding box.
                    const GAP_FACTOR = 3; // gap > GAP_FACTOR × avg font size = column break
                    let nextSyntheticBlockNum = pageData.blocks.reduce((m, b) => Math.max(m, b.block_num), 0) + 1;
                    const expandedBlocks = [];

                    pageData.blocks.forEach(block => {
                        const blockWords = pageData.words
                            ? pageData.words.filter(w => w.block_num === block.block_num)
                            : [];
                        if (blockWords.length < 2) {
                            expandedBlocks.push(block);
                            return;
                        }

                        // Group words by line
                        const lineMap = new Map();
                        blockWords.forEach(w => {
                            const ln = w.line_num ?? 0;
                            if (!lineMap.has(ln)) lineMap.set(ln, []);
                            lineMap.get(ln).push(w);
                        });

                        // For single-line blocks: check for column gaps
                        // For multi-line blocks: only split if ALL lines have matching column structure
                        const lineKeys = Array.from(lineMap.keys()).sort((a, b) => a - b);

                        // Determine column breaks from the first line (or longest line)
                        let refLine = lineMap.get(lineKeys[0]);
                        lineKeys.forEach(k => {
                            if (lineMap.get(k).length > refLine.length) refLine = lineMap.get(k);
                        });
                        refLine.sort((a, b) => a.left - b.left);

                        // Find gaps in the reference line
                        const avgFontSize = refLine.reduce((s, w) => s + (w.font_size || 10), 0) / refLine.length;
                        const gapThreshold = avgFontSize * GAP_FACTOR;
                        const splitPositions = []; // midpoint x-coords of gaps
                        for (let i = 1; i < refLine.length; i++) {
                            const prevRight = refLine[i - 1].left + refLine[i - 1].width;
                            const curLeft = refLine[i].left;
                            const gap = curLeft - prevRight;
                            if (gap > gapThreshold) {
                                splitPositions.push((prevRight + curLeft) / 2);
                            }
                        }

                        if (splitPositions.length === 0) {
                            // No column gaps found — keep block as-is
                            expandedBlocks.push(block);
                            return;
                        }

                        // Split all words into column groups based on gap positions
                        const numCols = splitPositions.length + 1;
                        const columnWords = Array.from({ length: numCols }, () => []);
                        blockWords.forEach(w => {
                            const wCenter = w.left + w.width / 2;
                            let col = 0;
                            for (let s = 0; s < splitPositions.length; s++) {
                                if (wCenter > splitPositions[s]) col = s + 1;
                            }
                            columnWords[col].push(w);
                        });

                        // Create a sub-block for each non-empty column
                        columnWords.forEach(words => {
                            if (words.length === 0) return;
                            let minL = Infinity, minT = Infinity, maxR = -Infinity, maxB = -Infinity;
                            words.forEach(w => {
                                minL = Math.min(minL, w.left);
                                minT = Math.min(minT, w.top);
                                maxR = Math.max(maxR, w.left + w.width);
                                maxB = Math.max(maxB, w.top + w.height);
                            });
                            const subNum = nextSyntheticBlockNum++;
                            // Re-tag words so they belong to the new sub-block
                            words.forEach(w => { w.block_num = subNum; });

                            // Build text_lines grouped by line_num
                            const subLineMap = new Map();
                            words.forEach(w => {
                                const ln = w.line_num ?? 0;
                                if (!subLineMap.has(ln)) subLineMap.set(ln, []);
                                subLineMap.get(ln).push(w);
                            });
                            const textLines = [];
                            Array.from(subLineMap.keys()).sort((a, b) => a - b).forEach(ln => {
                                const lineWords = subLineMap.get(ln).sort((a, b) => a.left - b.left);
                                textLines.push(lineWords.map(w => w.text).join(' '));
                            });

                            expandedBlocks.push({
                                ...block,
                                block_num: subNum,
                                left: minL,
                                top: minT,
                                width: maxR - minL,
                                height: maxB - minT,
                                text: textLines.join('\n'),
                                text_lines: textLines,
                            });
                        });
                    });

                    // Persist the expanded blocks back into pageData so subsequent
                    // re-renders (toggle off/on, zoom, etc.) see the already-split
                    // blocks with matching word block_num values.
                    pageData.blocks = expandedBlocks;

                    // Sort blocks by vertical position (top to bottom) then horizontal (left to right)
                    const sortedBlocks = [...expandedBlocks].sort((a, b) => {
                        const topDiff = a.top - b.top;
                        if (Math.abs(topDiff) > 5) return topDiff;
                        return a.left - b.left;
                    });

                    // Pre-compute base rectangles for all blocks so we can clamp padding to prevent overlaps
                    const blockBaseRects = sortedBlocks.map((blk) => {
                        const blkKey = `block-${pageData.page_number}-${blk.block_num}`;
                        const blkEdit = getOverlayStoredEdit(blkKey);
                        const blkWords = pageData.words
                            ? pageData.words.filter((w) => w.block_num === blk.block_num)
                            : [];

                        let bL = blk.left, bT = blk.top, bW = blk.width, bH = blk.height;
                        if (!blkEdit && blkWords.length > 0) {
                            const bounds = getWordBounds(blkWords);
                            if (bounds) { bL = bounds.left; bT = bounds.top; bW = bounds.width; bH = bounds.height; }
                        }

                        const baseL = blkEdit ? blkEdit.bbox[0] : bL;
                        const baseT = blkEdit ? blkEdit.bbox[1] : bT;
                        const baseW = blkEdit ? (blkEdit.bbox[2] - blkEdit.bbox[0]) : bW;
                        const baseH = blkEdit ? (blkEdit.bbox[3] - blkEdit.bbox[1]) : bH;
                        const desired = blkEdit ? 0 : computeOverlayPaddingPdf(blk);
                        return { baseL, baseT, baseW, baseH, desired };
                    });

                    // For each block, clamp its padding so padded boxes never overlap neighbours
                    const blockClampedPad = blockBaseRects.map((rect, i) => {
                        let maxPad = rect.desired;
                        for (let j = 0; j < blockBaseRects.length; j++) {
                            if (j === i) continue;
                            const o = blockBaseRects[j];
                            // Check if blocks share horizontal span (potential vertical overlap)
                            const hOverlap = rect.baseL < o.baseL + o.baseW && rect.baseL + rect.baseW > o.baseL;
                            // Check if blocks share vertical span (potential horizontal overlap)
                            const vOverlap = rect.baseT < o.baseT + o.baseH && rect.baseT + rect.baseH > o.baseT;

                            if (hOverlap) {
                                let gap;
                                if (o.baseT >= rect.baseT + rect.baseH) {
                                    gap = o.baseT - (rect.baseT + rect.baseH);
                                } else if (rect.baseT >= o.baseT + o.baseH) {
                                    gap = rect.baseT - (o.baseT + o.baseH);
                                } else {
                                    gap = 0;
                                }
                                maxPad = Math.min(maxPad, gap / 2);
                            }
                            if (vOverlap) {
                                let gap;
                                if (o.baseL >= rect.baseL + rect.baseW) {
                                    gap = o.baseL - (rect.baseL + rect.baseW);
                                } else if (rect.baseL >= o.baseL + o.baseW) {
                                    gap = rect.baseL - (o.baseL + o.baseW);
                                } else {
                                    gap = 0;
                                }
                                maxPad = Math.min(maxPad, gap / 2);
                            }
                        }
                        return Math.max(0, maxPad);
                    });

                    // ── Left-edge snapping ──
                    // Find the dominant left margin on the page and snap all nearby
                    // blocks to it so all left-aligned boxes share the exact same edge.
                    const LEFT_SNAP_THRESHOLD_PDF = 30; // PDF points tolerance
                    // Collect non-edited block left positions
                    const leftPositions = [];
                    blockBaseRects.forEach((rect, i) => {
                        const blkKey = `block-${pageData.page_number}-${sortedBlocks[i].block_num}`;
                        if (getOverlayStoredEdit(blkKey)) return;
                        leftPositions.push({ idx: i, left: rect.baseL });
                    });
                    // Group left positions by proximity using union-find style
                    const leftGroups = [];
                    leftPositions.forEach(({ idx, left }) => {
                        let bestGroup = null;
                        let bestDist = LEFT_SNAP_THRESHOLD_PDF;
                        for (const group of leftGroups) {
                            const dist = Math.abs(left - group.minLeft);
                            const distMax = Math.abs(left - group.maxLeft);
                            const closest = Math.min(dist, distMax);
                            if (closest < bestDist) {
                                bestDist = closest;
                                bestGroup = group;
                            }
                        }
                        if (bestGroup) {
                            bestGroup.indices.push(idx);
                            bestGroup.minLeft = Math.min(bestGroup.minLeft, left);
                            bestGroup.maxLeft = Math.max(bestGroup.maxLeft, left);
                        } else {
                            leftGroups.push({ minLeft: left, maxLeft: left, indices: [idx] });
                        }
                    });
                    // Find the dominant group (most blocks) — this is the page left margin
                    let dominantGroup = leftGroups.length ? leftGroups[0] : null;
                    for (const group of leftGroups) {
                        if (group.indices.length > (dominantGroup ? dominantGroup.indices.length : 0)) {
                            dominantGroup = group;
                        }
                    }
                    // Merge any smaller groups that are close to the dominant group
                    if (dominantGroup && dominantGroup.indices.length >= 2) {
                        for (const group of leftGroups) {
                            if (group === dominantGroup) continue;
                            if (Math.abs(group.minLeft - dominantGroup.minLeft) < LEFT_SNAP_THRESHOLD_PDF ||
                                Math.abs(group.maxLeft - dominantGroup.minLeft) < LEFT_SNAP_THRESHOLD_PDF) {
                                dominantGroup.indices.push(...group.indices);
                                dominantGroup.minLeft = Math.min(dominantGroup.minLeft, group.minLeft);
                                group.indices = []; // mark as merged
                            }
                        }
                    }
                    // Build per-block snapped left positions
                    const blockSnappedLeft = blockBaseRects.map((r) => r.baseL);
                    const blockSnappedWidthExtra = blockBaseRects.map(() => 0);
                    // Apply snapping for all groups (including dominant and remaining)
                    for (const group of leftGroups) {
                        if (group.indices.length < 2) continue;
                        const snapTarget = group.minLeft;
                        group.indices.forEach(i => {
                            const shift = blockBaseRects[i].baseL - snapTarget;
                            if (shift > 0) {
                                blockSnappedLeft[i] = snapTarget;
                                blockSnappedWidthExtra[i] = shift;
                            }
                        });
                    }
                    console.log('Left-edge snap groups:', leftGroups.map(g => ({
                        minLeft: g.minLeft,
                        count: g.indices.length,
                        indices: g.indices
                    })));

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
                        field.style.background = 'transparent';
                        field.style.border = '1px dashed rgba(66, 133, 244, 0.5)';
                        field.style.pointerEvents = 'auto';
                        field.style.cursor = 'move';
                        field.style.padding = '0';
                        field.style.minWidth = '40px';
                        field.style.minHeight = '20px';
                        field.style.boxSizing = 'border-box';
                        field.style.overflow = 'visible';

                        // Render the text content
                        const textSpan = document.createElement('div');
                        textSpan.contentEditable = true;
                        const hasStoredEdit = storedEdit && storedEdit.new_text != null;
                        textSpan.textContent = '';
                        textSpan.style.display = 'block';
                        textSpan.style.whiteSpace = 'pre';
                        textSpan.style.wordBreak = 'normal';
                        textSpan.style.width = '100%';
                        textSpan.style.minHeight = '100%';
                        textSpan.style.outline = 'none';
                        textSpan.style.cursor = 'text';
                        textSpan.style.userSelect = 'text';
                        textSpan.style.padding = '0';
                        textSpan.style.margin = '0';
                        textSpan.style.overflow = 'hidden';
                        
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

                        // Apply positioning from PyMuPDF data (padding clamped to prevent overlap)
                        const paddingPdf = storedEdit ? 0 : blockClampedPad[blockIndex];
                        const paddingX = paddingPdf * scaleX;
                        const paddingY = paddingPdf * scaleY;
                        // Use snapped left position for non-edited blocks so left-aligned
                        // boxes share the same page edge.
                        const snappedLeft = storedEdit ? storedEdit.bbox[0] : blockSnappedLeft[blockIndex];
                        const snapWidthExtra = storedEdit ? 0 : blockSnappedWidthExtra[blockIndex];
                        const baseLeft = snappedLeft;
                        const baseTop = storedEdit ? storedEdit.bbox[1] : blockTop;
                        const baseWidth = (storedEdit ? (storedEdit.bbox[2] - storedEdit.bbox[0]) : blockWidth) + snapWidthExtra;
                        const baseHeight = storedEdit ? (storedEdit.bbox[3] - storedEdit.bbox[1]) : blockHeight;

                        field.style.left = ((baseLeft * scaleX) - paddingX) + 'px';
                        field.style.top = ((baseTop * scaleY) - paddingY) + 'px';
                        field.style.width = ((baseWidth * scaleX) + (paddingX * 2)) + 'px';
                        field.style.height = ((baseHeight * scaleY) + (paddingY * 2)) + 'px';
                        field.style.zIndex = blockIndex + 1;

                        // Apply CSS padding so text content is pushed inward to the correct
                        // position (the outer box is expanded for click-targeting, but text
                        // must stay at its original PDF coordinate).
                        field.style.padding = paddingY + 'px ' + paddingX + 'px';

                        if (!storedEdit && blockWords.length > 0) {
                            const bounds = getWordBounds(blockWords);
                            if (bounds) {
                                // Use snapped left instead of raw bounds.left
                                const snappedBoundsLeft = blockSnappedLeft[blockIndex];
                                const boundsWidthExtra = blockSnappedWidthExtra[blockIndex];
                                const expectedLeft = (snappedBoundsLeft * scaleX) - paddingX;
                                const expectedTop = (bounds.top * scaleY) - paddingY;
                                const expectedWidth = ((bounds.width + boundsWidthExtra) * scaleX) + (paddingX * 2);
                                const expectedHeight = (bounds.height * scaleY) + (paddingY * 2);

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
                        field.dataset.padding = paddingPdf;

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
                            // Get current text color from the field dataset or computed style
                            const currentTextColor = field.dataset.textColor || computedStyle.color || '#000000';
                            const richHtml = buildBlockRichHtml(textSpan, fontFamily, fontWeight, fontStyle, currentFontSizePx, currentLineHeightPx, currentTextColor);
                            
                            // Create or update the edit record with CORRECT field mapping
                            // Account for CSS padding: field.style.left/top include padding offset,
                            // and width/height include 2*padding. We need the CONTENT bbox (text area only).
                            const pad = parseFloat(field.dataset.padding) || 0;
                            const currentLeft = (parseFloat(field.style.left) / scaleX) + pad;
                            const currentTop = (parseFloat(field.style.top) / scaleY) + pad;
                            const currentWidth = (parseFloat(field.style.width) / scaleX) - (pad * 2);
                            const currentHeight = (parseFloat(field.style.height) / scaleY) - (pad * 2);
                            
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
                                font_weight: fontWeight,            // FONT WEIGHT (400, 700, etc.)
                                font_style: fontStyle,              // FONT STYLE (normal, italic)
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
                        // Activate field on click (anywhere on the box)
                        field.addEventListener('click', function(e) {
                            if (e.target.closest('.box-menu')) return;
                            // Deactivate all other fields first
                            overlay.querySelectorAll('.overlay-field.active').forEach(f => {
                                if (f !== field) f.classList.remove('active');
                            });
                            field.classList.add('active');
                            setOverlaySelection(field);
                        });
                        textSpan.addEventListener('focus', function() {
                            overlay.querySelectorAll('.overlay-field.active').forEach(f => {
                                if (f !== field) f.classList.remove('active');
                            });
                            field.classList.add('active');
                        });
                        textSpan.addEventListener('blur', function(e) {
                            // Delay removing active class to allow button clicks to register
                            setTimeout(() => {
                                // Only remove if not clicking on handles/menu
                                if (!field.contains(document.activeElement) && !field.matches(':hover')) {
                                    field.classList.remove('active');
                                }
                            }, 250);
                        });
                        
                        // Add drag-to-move functionality
                        (function() {
                            let isDragging = false;
                            let dragStart = { x: 0, y: 0 };
                            
                            // Create box menu toolbar at top-right
                            const boxMenu = document.createElement('div');
                            boxMenu.className = 'box-menu';
                            
                            const dragBtn = document.createElement('button');
                            dragBtn.className = 'menu-drag';
                            dragBtn.innerHTML = '✥ Move';
                            dragBtn.title = 'Drag to move this block';
                            
                            const divider1 = document.createElement('div');
                            divider1.className = 'menu-divider';
                            
                            const splitBtn = document.createElement('button');
                            splitBtn.className = 'menu-split';
                            splitBtn.innerHTML = '✂ Split';
                            splitBtn.title = 'Split this block into two halves';
                            
                            const divider2 = document.createElement('div');
                            divider2.className = 'menu-divider';
                            
                            const deleteBtn = document.createElement('button');
                            deleteBtn.className = 'menu-delete';
                            deleteBtn.innerHTML = '🗑 Delete';
                            deleteBtn.title = 'Delete this text block';
                            
                            boxMenu.appendChild(dragBtn);
                            boxMenu.appendChild(divider1);
                            boxMenu.appendChild(splitBtn);
                            boxMenu.appendChild(divider2);
                            boxMenu.appendChild(deleteBtn);
                            field.appendChild(boxMenu);
                            
                            // ── Split handler ─────────────────────────────────
                            splitBtn.addEventListener('click', function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                pushUndoState();

                                // Get current field bbox in PDF coords
                                const pad = parseFloat(field.dataset.padding) || 0;
                                const fLeft  = (parseFloat(field.style.left) / scaleX) + pad;
                                const fTop   = (parseFloat(field.style.top) / scaleY) + pad;
                                const fWidth = (parseFloat(field.style.width) / scaleX) - (pad * 2);
                                const fHeight = (parseFloat(field.style.height) / scaleY) - (pad * 2);
                                const midX   = fLeft + fWidth / 2;

                                // Partition words into left/right by each word's center-x
                                const bWords = pageData.words
                                    ? pageData.words.filter(w => w.block_num === block.block_num)
                                    : [];
                                let leftWords = [];
                                let rightWords = [];
                                if (bWords.length > 0) {
                                    bWords.forEach(w => {
                                        const wCenterX = w.left + w.width / 2;
                                        if (wCenterX < midX) leftWords.push(w);
                                        else rightWords.push(w);
                                    });
                                } else {
                                    // No word data — split text string in half
                                    const mid = Math.ceil(blockText.length / 2);
                                    leftWords = [{ text: blockText.substring(0, mid), left: fLeft, top: fTop, width: fWidth / 2, height: fHeight }];
                                    rightWords = [{ text: blockText.substring(mid), left: midX, top: fTop, width: fWidth / 2, height: fHeight }];
                                }

                                // Compute tight bboxes from actual word positions
                                const wordBbox = (words) => {
                                    if (!words.length) return null;
                                    let l = Infinity, t = Infinity, r = -Infinity, b = -Infinity;
                                    words.forEach(w => {
                                        l = Math.min(l, w.left);
                                        t = Math.min(t, w.top);
                                        r = Math.max(r, w.left + w.width);
                                        b = Math.max(b, w.top + w.height);
                                    });
                                    return [l, t, r, b];
                                };

                                const leftBbox  = wordBbox(leftWords) || [fLeft, fTop, midX, fTop + fHeight];
                                const rightBbox = wordBbox(rightWords) || [midX, fTop, fLeft + fWidth, fTop + fHeight];

                                const leftText  = leftWords.map(w => w.text).join(' ');
                                const rightText = rightWords.map(w => w.text).join(' ');

                                // Keys for the two halves
                                const maxBlk = pageData.blocks.reduce((m, b) => Math.max(m, b.block_num), 0);
                                const leftBlockNum  = maxBlk + 1;
                                const rightBlockNum = maxBlk + 2;
                                const leftKey  = `block-${pageData.page_number}-${leftBlockNum}`;
                                const rightKey = `block-${pageData.page_number}-${rightBlockNum}`;

                                // Build edit entries
                                const makeEdit = (text, bbox, bNum) => ({
                                    page_number: pageData.page_number,
                                    block_num: bNum,
                                    original_text: blockText,
                                    new_text: text,
                                    rich_html: null,
                                    bbox: bbox,
                                    original_bbox: [blockLeft, blockTop, blockLeft + blockWidth, blockTop + blockHeight],
                                    origin_x: bbox[0],
                                    origin_y: bbox[3],
                                    font: block.font,
                                    font_size: block.font_size,
                                    font_weight: fontWeight,
                                    font_style: fontStyle,
                                    font_xref: block.font_xref,
                                    line_height: lineHeightValue || null,
                                    color: field.dataset.textColor || '#000000'
                                });

                                // Delete original block
                                overlayEditedFields.set(key, {
                                    page_number: pageData.page_number,
                                    block_num: block.block_num,
                                    original_text: blockText,
                                    new_text: '',
                                    rich_html: null,
                                    bbox: [fLeft, fTop, fLeft + fWidth, fTop + fHeight],
                                    original_bbox: [blockLeft, blockTop, blockLeft + blockWidth, blockTop + blockHeight],
                                    font_xref: block.font_xref,
                                    font: block.font,
                                    font_size: block.font_size,
                                    font_weight: fontWeight,
                                    font_style: fontStyle,
                                    line_height: lineHeightValue || null,
                                    color: field.dataset.textColor || '#000000'
                                });

                                // Add two new edit entries
                                overlayEditedFields.set(leftKey, makeEdit(leftText, leftBbox, leftBlockNum));
                                overlayEditedFields.set(rightKey, makeEdit(rightText, rightBbox, rightBlockNum));

                                // Inject synthetic blocks into extraction data so re-render sees them
                                const synBase = { ...block, text: '', text_lines: [] };
                                pageData.blocks.push({
                                    ...synBase,
                                    block_num: leftBlockNum,
                                    left: leftBbox[0], top: leftBbox[1],
                                    width: leftBbox[2] - leftBbox[0],
                                    height: leftBbox[3] - leftBbox[1],
                                    text: leftText,
                                    text_lines: [leftText],
                                });
                                pageData.blocks.push({
                                    ...synBase,
                                    block_num: rightBlockNum,
                                    left: rightBbox[0], top: rightBbox[1],
                                    width: rightBbox[2] - rightBbox[0],
                                    height: rightBbox[3] - rightBbox[1],
                                    text: rightText,
                                    text_lines: [rightText],
                                });

                                // Remove original field and re-render
                                selectedOverlayField = null;
                                if (field.parentNode) field.parentNode.removeChild(field);

                                persistOverlayEdits();
                                updateOverlaySaveButton();
                                renderPdfWithOverlay(true);
                                setStatus('Block split into two halves', 'ok');
                            });
                            
                            deleteBtn.addEventListener('click', function(e) {
                                console.log('delete clicked');
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
                                    const currentTextColor = field.dataset.textColor || computedStyle.color || '#000000';
                                    const richHtml = buildBlockRichHtml(textSpan, fontFamily, fontWeight, fontStyle, currentFontSizePx, currentLineHeightPx, currentTextColor);
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
                                    font_weight: fontWeight,
                                    font_style: fontStyle,
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
                            
                            const startDrag = (e) => {
                                // Save state before drag starts
                                pushUndoState();
                                
                                isDragging = true;
                                dragStart = { x: e.clientX, y: e.clientY };
                                field.style.cursor = 'move';
                                textSpan.style.pointerEvents = 'none'; // Prevent text selection during drag
                                dragBtn.style.cursor = 'grabbing';
                                // Hide menu during drag for cleaner UX
                                boxMenu.style.display = 'none';
                                e.preventDefault();
                                e.stopPropagation();
                            };
                            
                            dragBtn.addEventListener('mousedown', startDrag);
                            
                            // Allow dragging the box itself (not the text or handles).
                            field.addEventListener('mousedown', (e) => {
                                if (e.target.closest('.box-menu') || e.target.classList.contains('resize-handle')) {
                                    return;
                                }
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
                                    dragBtn.style.cursor = 'grab';
                                    boxMenu.style.display = '';
                                    textSpan.style.pointerEvents = 'auto'; // Re-enable text interaction
                                    
                                    // Save the new position
                                    // Account for CSS padding: field position includes padding offset
                                    const pad = parseFloat(field.dataset.padding) || 0;
                                    const newLeft = (parseFloat(field.style.left) / scaleX) + pad;
                                    const newTop = (parseFloat(field.style.top) / scaleY) + pad;
                                    const width = (parseFloat(field.style.width) / scaleX) - (pad * 2);
                                    const height = (parseFloat(field.style.height) / scaleY) - (pad * 2);
                                    
                                    // Calculate NEW origin based on the NEW position (not original!)
                                    // origin_x is the left edge, origin_y is the bottom edge (baseline area)
                                    const newOriginX = newLeft;
                                    const newOriginY = newTop + height;
                                    
                                    // Get current text — if the user hasn't typed anything
                                    // the spans are still absolutely-positioned so innerText
                                    // would mangle the spacing. Use the original blockText.
                                    const hasPositionedSpans = textSpan.querySelector('span[style*="position: absolute"]') ||
                                        textSpan.querySelector('span[style*="position:absolute"]');
                                    let currentText;
                                    if (hasPositionedSpans) {
                                        // Words are still in positioned spans — text is unedited
                                        currentText = blockText;
                                    } else {
                                        currentText = textSpan.innerText;
                                        const originalClean = blockText.replace(/\s+/g, '');
                                        const currentClean = currentText.replace(/\s+/g, '');
                                        if (originalClean === currentClean) {
                                            currentText = blockText;
                                        }
                                    }
                                    const computedStyle = window.getComputedStyle(textSpan);
                                    const currentFontSizePx = computedStyle.fontSize;
                                    const currentLineHeightPx = computedStyle.lineHeight !== 'normal' ? computedStyle.lineHeight : '';
                                    const currentFontSizePdf = parseFloat(field.dataset.fontSize || (parseFloat(currentFontSizePx) / scaleY) || block.font_size);
                                    const currentTextColor = field.dataset.textColor || computedStyle.color || '#000000';
                                    const richHtml = buildBlockRichHtml(textSpan, fontFamily, fontWeight, fontStyle, currentFontSizePx, currentLineHeightPx, currentTextColor);
                                    
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
                                        font_weight: fontWeight,               // FONT WEIGHT (400, 700, etc.)
                                        font_style: fontStyle,                 // FONT STYLE (normal, italic)
                                        font_xref: block.font_xref,            // FONT REFERENCE
                                        line_height: lineHeightValue || null,
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

                            // Page dimension limits (in pixels)
                            const maxPageWidth = parseFloat(field.dataset.canvasWidth || '0') || (field.parentElement ? field.parentElement.clientWidth : Infinity);
                            const maxPageHeight = parseFloat(field.dataset.canvasHeight || '0') || (field.parentElement ? field.parentElement.clientHeight : Infinity);

                            // Convert absolutely-positioned word spans to flowing text once at
                            // the start of the resize so that text wraps naturally with the box.
                            // Font size is NEVER changed — only the box dimensions change.
                            let normalizedOnce = false;
                            const normalizeOnce = () => {
                                if (normalizedOnce) return;
                                const hasPositionedSpans = textSpan.querySelector('span') &&
                                    Array.from(textSpan.querySelectorAll('span')).some(s => s.style.position === 'absolute');
                                if (!hasPositionedSpans) return;
                                // Collect individual word-span texts and join with spaces.
                                // textContent alone concatenates without spaces since the
                                // gaps between words were purely positional (CSS left/top).
                                const spans = Array.from(textSpan.querySelectorAll('span'));
                                const plainText = spans.map(s => s.textContent).join(' ');
                                textSpan.textContent = plainText;
                                textSpan.style.whiteSpace = 'pre-wrap';
                                textSpan.style.wordBreak = 'break-word';
                                textSpan.style.width = '100%';
                                textSpan.style.height = 'auto';
                                normalizedOnce = true;
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
                                
                                // Enforce minimum width
                                if (newWidth < 40) {
                                    if (position.includes('w')) {
                                        newLeft = startLeft + startWidth - 40;
                                    }
                                    newWidth = 40;
                                }

                                // Clamp to page dimensions — box must stay within the page
                                if (newLeft < 0) { newWidth += newLeft; newLeft = 0; }
                                if (newTop < 0) { newHeight += newTop; newTop = 0; }
                                if (newWidth > maxPageWidth) newWidth = maxPageWidth;
                                if (newHeight > maxPageHeight) newHeight = maxPageHeight;
                                if (newLeft + newWidth > maxPageWidth) newLeft = maxPageWidth - newWidth;
                                if (newTop + newHeight > maxPageHeight) newTop = maxPageHeight - newHeight;

                                // If width changed, normalize positioned spans to flowing text
                                // so text wraps naturally (only happens once)
                                if (newWidth !== startWidth) {
                                    normalizeOnce();
                                }
                                
                                // Apply dimensions (width first so scrollHeight reflects wrap)
                                field.style.left = newLeft + 'px';
                                field.style.top = newTop + 'px';
                                field.style.width = newWidth + 'px';
                                field.style.height = newHeight + 'px';

                                // NO font-size changes — font stays constant during resize

                                // Enforce minimum height: box can never be shorter than its text content
                                const contentHeight = Math.ceil(textSpan.scrollHeight || 0);
                                const minHeight = Math.max(20, contentHeight);
                                if (newHeight < minHeight) {
                                    if (position.includes('n')) {
                                        newTop = startTop + startHeight - minHeight;
                                        field.style.top = newTop + 'px';
                                    }
                                    newHeight = minHeight;
                                    field.style.height = newHeight + 'px';
                                }
                            };
                            
                            const onMouseUp = () => {
                                document.removeEventListener('mousemove', onMouseMove);
                                document.removeEventListener('mouseup', onMouseUp);
                                
                                // Save the new dimensions
                                // Account for CSS padding: field position includes padding offset
                                const pad = parseFloat(field.dataset.padding) || 0;
                                const newLeft = (parseFloat(field.style.left) / scaleX) + pad;
                                const newTop = (parseFloat(field.style.top) / scaleY) + pad;
                                const newWidth = (parseFloat(field.style.width) / scaleX) - (pad * 2);
                                const newHeight = (parseFloat(field.style.height) / scaleY) - (pad * 2);
                                
                                // Use CURRENT position for origin (where text should be inserted)
                                const newOriginX = newLeft;
                                const newOriginY = newTop + newHeight;
                                
                                // Get current text — if normalizeOnce() ran, spans are now
                                // flowing text and innerText works. If not (pure move via
                                // corner handle without width change), spans are still
                                // absolutely-positioned and innerText would mangle spacing.
                                const hasPositionedSpans2 = textSpan.querySelector('span[style*="position: absolute"]') ||
                                    textSpan.querySelector('span[style*="position:absolute"]');
                                let currentText;
                                if (hasPositionedSpans2) {
                                    currentText = blockText;
                                } else {
                                    currentText = textSpan.innerText;
                                    const originalClean = blockText.replace(/\s+/g, '');
                                    const currentClean = currentText.replace(/\s+/g, '');
                                    if (originalClean === currentClean) {
                                        currentText = blockText;
                                    }
                                }
                                const computedStyle = window.getComputedStyle(textSpan);
                                const currentFontSizePx = computedStyle.fontSize;
                                const currentLineHeightPx = computedStyle.lineHeight !== 'normal' ? computedStyle.lineHeight : '';
                                const currentFontSizePdf = parseFloat(field.dataset.fontSize || (parseFloat(currentFontSizePx) / scaleY) || block.font_size);
                                const currentTextColor = field.dataset.textColor || computedStyle.color || '#000000';
                                const richHtml = buildBlockRichHtml(textSpan, fontFamily, fontWeight, fontStyle, currentFontSizePx, currentLineHeightPx, currentTextColor);
                                
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
                                    font_weight: fontWeight,                    // FONT WEIGHT
                                    font_style: fontStyle,                      // FONT STYLE
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

                // --- Page dimension clamping ---
                const maxPageWidth = parseFloat(overlayResizingField.dataset.canvasWidth || '0');
                const maxPageHeight = parseFloat(overlayResizingField.dataset.canvasHeight || '0');

                if (newWidth < 20) newWidth = 20;
                if (newLeft < 0) { newWidth += newLeft; newLeft = 0; }
                if (maxPageWidth > 0 && newLeft + newWidth > maxPageWidth) {
                    newWidth = maxPageWidth - newLeft;
                }
                if (newTop < 0) { newHeight += newTop; newTop = 0; }
                if (maxPageHeight > 0 && newTop + newHeight > maxPageHeight) {
                    newHeight = maxPageHeight - newTop;
                }

                // --- Enable text wrapping (no font changes) ---
                if (overlayResizeStart.textSpan) {
                    overlayResizeStart.textSpan.style.whiteSpace = 'pre-wrap';
                    overlayResizeStart.textSpan.style.wordBreak = 'break-word';
                    overlayResizeStart.textSpan.style.width = '100%';
                    overlayResizeStart.textSpan.style.height = 'auto';
                }

                // Apply width first so text can reflow
                overlayResizingField.style.left = newLeft + 'px';
                overlayResizingField.style.top = newTop + 'px';
                overlayResizingField.style.width = newWidth + 'px';

                // --- Min-height from text content ---
                if (overlayResizeStart.textSpan) {
                    const contentHeight = Math.ceil(overlayResizeStart.textSpan.scrollHeight);
                    const minHeight = Math.max(15, contentHeight);
                    if (newHeight < minHeight) newHeight = minHeight;
                }

                overlayResizingField.style.height = newHeight + 'px';

                // NO font scaling - font size never changes during resize
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
                // Account for CSS padding: field position includes padding offset,
                // and dimensions include 2*padding. Subtract padding to get content bbox.
                const padPx = padding;  // padding is in pixels for word-level fields
                const pdfLeft = (currentLeft + padPx) / scaleX;
                const pdfTop = (currentTop + padPx) / scaleY;
                const pdfWidth = (currentWidth - padPx * 2) / scaleX;
                const pdfHeight = (currentHeight - padPx * 2) / scaleY;
                
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
            let aiViewerRendered = false;
            
            const pdfModeBar = document.getElementById('pdf-mode-bar');
            const selectionToolbar = document.getElementById('selection-toolbar');
            
            function updateTabStyles(activeButton) {
                tabButtons.forEach(btn => {
                    btn.classList.remove('active', 'text-white', 'border-blue-500');
                    btn.classList.add('text-gray-400', 'border-transparent');
                });
                activeButton.classList.add('active', 'text-white', 'border-blue-500');
                activeButton.classList.remove('text-gray-400', 'border-transparent');
            }
            
            tabButtons.forEach(button => {
                button.addEventListener('click', async () => {
                    const tabId = button.dataset.tab;
                    
                    // Update active tab button styles
                    updateTabStyles(button);
                    
                    // Update active tab content
                    tabContents.forEach(content => content.classList.remove('active'));
                    document.getElementById(tabId).classList.add('active');
                    
                    // Show/hide toolbars based on active tab
                    if (tabId === 'pdf-editor') {
                        if (pdfModeBar) pdfModeBar.style.display = '';
                        if (selectionToolbar) selectionToolbar.style.display = '';
                    } else if (tabId === 'extracted-text') {
                        if (pdfModeBar) pdfModeBar.style.display = 'none';
                        if (selectionToolbar) selectionToolbar.style.display = 'none';
                    }
                    
                    // Render PDF in AI Generator tab when clicked
                    if (tabId === 'extracted-text' && !aiViewerRendered) {
                        aiViewerRendered = true;
                        // Don't render PDF pages in AI Generator tab
                        // Load saved sections from database and recreate template
                        loadSectionsFromDatabase();
                    }
                });
            });
            
            async function renderAIViewer() {
                // Function disabled - AI Generator tab doesn't show PDF pages
                return;
            }
            
            // Generate from Template Modal
            const generateFromTemplateBtn = document.getElementById('generate-from-template');
            const templateModal = document.getElementById('generate-from-template-modal');
            const templateModalClose = document.getElementById('template-modal-close');
            const templateModalCancel = document.getElementById('template-modal-cancel');
            const templateModalGenerate = document.getElementById('template-modal-generate');
            const templatePrefabs = document.querySelectorAll('.template-prefab');
            const templateSectionsContainer = document.getElementById('template-sections-container');
            const templateSectionsList = document.getElementById('template-sections-list');
            
            let selectedLayout = null;
            let templateSections = [];
            let builderSections = []; // For custom builder
            
            // Tab switching in modal
            const templateTabs = document.querySelectorAll('[data-template-tab]');
            const templatePanels = document.querySelectorAll('[data-template-panel]');
            
            console.log('Template tabs found:', templateTabs.length);
            console.log('Template panels found:', templatePanels.length);
            
            templateTabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    const targetPanel = tab.dataset.templateTab;
                    
                    console.log('Tab clicked:', targetPanel);
                    
                    // Update tabs
                    templateTabs.forEach(t => t.classList.remove('active'));
                    tab.classList.add('active');
                    
                    // Update panels
                    templatePanels.forEach(p => {
                        p.classList.remove('active');
                        console.log('Removed active from panel:', p.dataset.templatePanel);
                    });
                    const targetPanelEl = document.querySelector(`[data-template-panel="${targetPanel}"]`);
                    if (targetPanelEl) {
                        targetPanelEl.classList.add('active');
                        console.log('Added active to panel:', targetPanel);
                    } else {
                        console.error('Could not find panel:', targetPanel);
                    }
                    
                    // Update generate button state after a brief delay to ensure DOM is updated
                    setTimeout(() => {
                        updateGenerateButton();
                    }, 10);
                });
            });
            
            if (generateFromTemplateBtn) {
                generateFromTemplateBtn.addEventListener('click', () => {
                    templateModal.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                });
            }
            
            const closeTemplateModal = () => {
                templateModal.style.display = 'none';
                document.body.style.overflow = '';
                selectedLayout = null;
                templateSections = [];
                builderSections = [];
                templatePrefabs.forEach(p => p.classList.remove('selected'));
                templateSectionsContainer.style.display = 'none';
                templateSectionsList.innerHTML = '';
                clearBuilderCanvas();
                templateModalGenerate.disabled = true;
                
                // Reset to prefabs tab
                templateTabs.forEach(t => t.classList.remove('active'));
                templateTabs[0].classList.add('active');
                templatePanels.forEach(p => p.classList.remove('active'));
                templatePanels[0].classList.add('active');
            };
            
            if (templateModalClose) {
                templateModalClose.addEventListener('click', closeTemplateModal);
            }
            if (templateModalCancel) {
                templateModalCancel.addEventListener('click', closeTemplateModal);
            }
            
            // Handle prefab selection
            templatePrefabs.forEach(prefab => {
                prefab.addEventListener('click', () => {
                    const layout = prefab.dataset.layout;
                    templatePrefabs.forEach(p => p.classList.remove('selected'));
                    prefab.classList.add('selected');
                    selectedLayout = layout;
                    
                    templateSections = getLayoutSections(layout);
                    renderTemplateSections();
                    templateSectionsContainer.style.display = 'block';
                    templateModalGenerate.disabled = false;
                });
            });
            
            function getLayoutSections(layout) {
                const sectionTypes = {
                    title: { name: 'Title', icon: '📄', defaultHeight: 10, defaultWidth: 100, defaultX: 0, defaultY: 0 },
                    paragraph: { name: 'Paragraph', icon: '📝', defaultHeight: 20, defaultWidth: 100, defaultX: 0, defaultY: 0 },
                    chart: { name: 'Chart', icon: '📊', defaultHeight: 30, defaultWidth: 50, defaultX: 0, defaultY: 0 },
                    graphic: { name: 'Graphic', icon: '🖼️', defaultHeight: 30, defaultWidth: 50, defaultX: 0, defaultY: 0 }
                };
                
                const layouts = {
                    'title-abstract-body': [
                        { type: 'title', height: 10, ...sectionTypes.title },
                        { type: 'paragraph', height: 20, ...sectionTypes.paragraph, name: 'Abstract' },
                        { type: 'paragraph', height: 70, ...sectionTypes.paragraph, name: 'Body' }
                    ],
                    'intro-body-conclusion': [
                        { type: 'paragraph', height: 20, ...sectionTypes.paragraph, name: 'Introduction' },
                        { type: 'paragraph', height: 60, ...sectionTypes.paragraph, name: 'Body' },
                        { type: 'paragraph', height: 20, ...sectionTypes.paragraph, name: 'Conclusion' }
                    ],
                    'full-body-references': [
                        { type: 'paragraph', height: 80, ...sectionTypes.paragraph, name: 'Body Content' },
                        { type: 'paragraph', height: 20, ...sectionTypes.paragraph, name: 'References' }
                    ],
                    'methods-results': [
                        { type: 'chart', height: 50, ...sectionTypes.chart, name: 'Methods' },
                        { type: 'chart', height: 50, ...sectionTypes.chart, name: 'Results' }
                    ],
                    'full-document': [
                        { type: 'title', height: 8, ...sectionTypes.title },
                        { type: 'paragraph', height: 12, ...sectionTypes.paragraph, name: 'Abstract' },
                        { type: 'paragraph', height: 15, ...sectionTypes.paragraph, name: 'Introduction' },
                        { type: 'paragraph', height: 40, ...sectionTypes.paragraph, name: 'Body' },
                        { type: 'paragraph', height: 15, ...sectionTypes.paragraph, name: 'Conclusion' },
                        { type: 'graphic', height: 10, ...sectionTypes.graphic, name: 'References' }
                    ],
                    // Multi-column layouts
                    'two-column': [
                        { type: 'title', height: 10, ...sectionTypes.title, defaultWidth: 100, defaultX: 0 },
                        { type: 'paragraph', height: 90, ...sectionTypes.paragraph, defaultWidth: 48, defaultX: 1, name: 'Left Column' },
                        { type: 'paragraph', height: 90, ...sectionTypes.paragraph, defaultWidth: 48, defaultX: 51, name: 'Right Column' }
                    ],
                    'two-column-image': [
                        { type: 'title', height: 10, ...sectionTypes.title, defaultWidth: 100, defaultX: 0 },
                        { type: 'paragraph', height: 90, ...sectionTypes.paragraph, defaultWidth: 55, defaultX: 1, name: 'Text Column' },
                        { type: 'graphic', height: 90, ...sectionTypes.graphic, defaultWidth: 42, defaultX: 57, name: 'Image' }
                    ],
                    'three-column': [
                        { type: 'title', height: 10, ...sectionTypes.title, defaultWidth: 100, defaultX: 0 },
                        { type: 'paragraph', height: 90, ...sectionTypes.paragraph, defaultWidth: 31, defaultX: 1, name: 'Column 1' },
                        { type: 'paragraph', height: 90, ...sectionTypes.paragraph, defaultWidth: 31, defaultX: 34, name: 'Column 2' },
                        { type: 'paragraph', height: 90, ...sectionTypes.paragraph, defaultWidth: 31, defaultX: 67, name: 'Column 3' }
                    ],
                    'image-gallery': [
                        { type: 'title', height: 10, ...sectionTypes.title, defaultWidth: 100, defaultX: 0 },
                        { type: 'graphic', height: 43, ...sectionTypes.graphic, defaultWidth: 48, defaultX: 1, name: 'Image 1' },
                        { type: 'graphic', height: 43, ...sectionTypes.graphic, defaultWidth: 48, defaultX: 51, name: 'Image 2' },
                        { type: 'graphic', height: 43, ...sectionTypes.graphic, defaultWidth: 48, defaultX: 1, name: 'Image 3' },
                        { type: 'graphic', height: 43, ...sectionTypes.graphic, defaultWidth: 48, defaultX: 51, name: 'Image 4' }
                    ],
                    'hero-image': [
                        { type: 'graphic', height: 50, ...sectionTypes.graphic, defaultWidth: 100, defaultX: 0, name: 'Hero Image' },
                        { type: 'title', height: 10, ...sectionTypes.title, defaultWidth: 100, defaultX: 0 },
                        { type: 'paragraph', height: 40, ...sectionTypes.paragraph, defaultWidth: 100, defaultX: 0, name: 'Description' }
                    ],
                    'sidebar-layout': [
                        { type: 'paragraph', height: 100, ...sectionTypes.paragraph, defaultWidth: 68, defaultX: 0, name: 'Main Content' },
                        { type: 'graphic', height: 40, ...sectionTypes.graphic, defaultWidth: 28, defaultX: 71, name: 'Sidebar Image' },
                        { type: 'paragraph', height: 55, ...sectionTypes.paragraph, defaultWidth: 28, defaultX: 71, name: 'Sidebar Text' }
                    ]
                };
                
                const sections = layouts[layout] || [];
                
                // Compute cumulative Y positions for stacked sections
                // Sections sharing same row (overlapping X ranges at same Y) are side-by-side (columns)
                return computeSectionPositions(sections);
            }
            
            // Compute proper Y positions for sections - stacked sections get cumulative Y
            function computeSectionPositions(sections) {
                if (sections.length === 0) return sections;
                
                // Check if this is a multi-column layout (sections have explicit non-zero defaultX)
                const hasExplicitPositions = sections.some(s => s.defaultX > 0);
                
                if (hasExplicitPositions) {
                    // For multi-column layouts, compute Y positions for stacked groups
                    // Group sections by their column (X range)
                    const processed = [];
                    const columnTracker = {}; // Track Y offset per column
                    
                    sections.forEach(section => {
                        const x = section.defaultX || 0;
                        const w = section.defaultWidth || 100;
                        const colKey = `${x}-${w}`;
                        
                        if (columnTracker[colKey] === undefined) {
                            // First section in this column - find existing Y from previous full-width row
                            const fullWidthY = processed
                                .filter(s => (s.defaultWidth || 100) >= 95 && s.defaultX <= 2)
                                .reduce((acc, s) => acc + s.height, 0);
                            columnTracker[colKey] = fullWidthY;
                        }
                        
                        const computedSection = { ...section, defaultY: columnTracker[colKey] };
                        columnTracker[colKey] += section.height;
                        processed.push(computedSection);
                    });
                    
                    return processed;
                } else {
                    // Simple stacked layout - accumulate Y positions
                    let cumulativeY = 0;
                    return sections.map(section => {
                        const result = { ...section, defaultY: cumulativeY };
                        cumulativeY += section.height;
                        return result;
                    });
                }
            }
            
            function renderTemplateSections() {
                templateSectionsList.innerHTML = '';
                templateSections.forEach((section, index) => {
                    const sectionEl = document.createElement('div');
                    sectionEl.className = 'template-section';
                    sectionEl.style.cssText = 'display: flex; align-items: center; gap: 12px; padding: 12px; background: rgba(255,255,255,0.08); border-radius: 8px; margin-bottom: 8px;';
                    sectionEl.innerHTML = `
                        <span style="font-size: 20px;">${section.icon}</span>
                        <span style="font-weight: 600; min-width: 100px; color: #f3f4f6;">${section.name}</span>
                        <input type="number" value="${section.height}" min="5" max="100" 
                               onchange="templateSections[${index}].height = parseInt(this.value)" 
                               style="width: 80px; padding: 6px 10px; border: 1px solid rgba(255,255,255,0.2); border-radius: 6px; background: rgba(255,255,255,0.05); color: #111827;"
                               placeholder="Height %">
                        <span style="font-size: 12px; color: #9ca3af;">%</span>
                    `;
                    templateSectionsList.appendChild(sectionEl);
                });
            }
            
            // Custom Builder - Visual Page Preview
            const sectionBlocksPalette = document.getElementById('section-blocks-palette');
            const pageBuilderCanvas = document.getElementById('page-builder-canvas');
            const builderEmptyState = document.getElementById('builder-empty-state');
            const builderClearBtn = document.getElementById('builder-clear-btn');
            
            let draggedBlock = null;
            let draggedCanvasSection = null;
            
            // Section type colors for visual distinction
            const sectionColors = {
                title: { bg: 'rgba(59,130,246,0.15)', border: 'rgba(59,130,246,0.6)' },
                paragraph: { bg: 'rgba(16,185,129,0.15)', border: 'rgba(16,185,129,0.6)' },
                chart: { bg: 'rgba(139,92,246,0.15)', border: 'rgba(139,92,246,0.6)' },
                graphic: { bg: 'rgba(245,158,11,0.15)', border: 'rgba(245,158,11,0.6)' }
            };
            
            // Make palette blocks draggable
            const sectionBlocks = sectionBlocksPalette.querySelectorAll('.section-block');
            sectionBlocks.forEach(block => {
                block.addEventListener('dragstart', (e) => {
                    draggedBlock = block.dataset.sectionType;
                    e.dataTransfer.effectAllowed = 'copy';
                });
                block.addEventListener('dragend', () => { draggedBlock = null; });
            });
            
            // Canvas drop zone
            pageBuilderCanvas.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
                pageBuilderCanvas.style.borderColor = 'rgba(59,130,246,1)';
                pageBuilderCanvas.style.boxShadow = '0 0 0 3px rgba(59,130,246,0.2)';
            });
            
            pageBuilderCanvas.addEventListener('dragleave', () => {
                pageBuilderCanvas.style.borderColor = 'rgba(59,130,246,0.4)';
                pageBuilderCanvas.style.boxShadow = '0 4px 20px rgba(0,0,0,0.2)';
            });
            
            pageBuilderCanvas.addEventListener('drop', (e) => {
                e.preventDefault();
                pageBuilderCanvas.style.borderColor = 'rgba(59,130,246,0.4)';
                pageBuilderCanvas.style.boxShadow = '0 4px 20px rgba(0,0,0,0.2)';
                
                if (draggedBlock) {
                    // Calculate drop position as percentage of canvas
                    const rect = pageBuilderCanvas.getBoundingClientRect();
                    const dropXPct = Math.round(((e.clientX - rect.left) / rect.width) * 100);
                    const dropYPct = Math.round(((e.clientY - rect.top) / rect.height) * 100);
                    addSectionToCanvas(draggedBlock, dropXPct, dropYPct);
                }
            });
            
            function addSectionToCanvas(sectionType, dropX, dropY) {
                const sectionTypes = {
                    title: { name: 'Title', icon: '📄', defaultHeight: 10, defaultWidth: 100, defaultX: 0, defaultY: 0 },
                    paragraph: { name: 'Paragraph', icon: '📝', defaultHeight: 20, defaultWidth: 100, defaultX: 0, defaultY: 0 },
                    chart: { name: 'Chart', icon: '📊', defaultHeight: 30, defaultWidth: 50, defaultX: 0, defaultY: 0 },
                    graphic: { name: 'Graphic', icon: '🖼️', defaultHeight: 30, defaultWidth: 50, defaultX: 0, defaultY: 0 }
                };
                
                const section = { type: sectionType, ...sectionTypes[sectionType] };
                
                // If dropped at a position, use that position
                if (dropX !== undefined && dropY !== undefined) {
                    section.defaultX = Math.max(0, Math.min(dropX - section.defaultWidth / 2, 100 - section.defaultWidth));
                    section.defaultY = Math.max(0, Math.min(dropY - section.defaultHeight / 2, 100 - section.defaultHeight));
                } else {
                    // Stack below existing sections
                    const totalH = builderSections.reduce((sum, s) => Math.max(sum, (s.defaultY || 0) + s.defaultHeight), 0);
                    section.defaultY = Math.min(totalH, 100 - section.defaultHeight);
                }
                
                builderSections.push(section);
                renderBuilderCanvas();
                updateGenerateButton();
            }
            
            function renderBuilderCanvas() {
                // Clear existing sections
                const existingSections = pageBuilderCanvas.querySelectorAll('.canvas-section');
                existingSections.forEach(s => s.remove());
                
                if (builderSections.length === 0) {
                    builderEmptyState.style.display = 'flex';
                    return;
                }
                
                builderEmptyState.style.display = 'none';
                
                // Render sections as positioned blocks on the visual page
                builderSections.forEach((section, index) => {
                    const colors = sectionColors[section.type] || sectionColors.paragraph;
                    
                    const sectionEl = document.createElement('div');
                    sectionEl.className = 'canvas-section';
                    sectionEl.dataset.index = index;
                    sectionEl.style.cssText = `
                        left: ${section.defaultX || 0}%;
                        top: ${section.defaultY || 0}%;
                        width: ${section.defaultWidth || 100}%;
                        height: ${section.defaultHeight || 20}%;
                        background: ${colors.bg};
                        border-color: ${colors.border};
                    `;
                    
                    sectionEl.innerHTML = `
                        <div class="canvas-section-label">
                            <span class="icon">${section.icon}</span>
                            <span class="name">${section.name}</span>
                        </div>
                        <button class="remove-btn" title="Remove">×</button>
                    `;
                    
                    // Remove button handler
                    const removeBtn = sectionEl.querySelector('.remove-btn');
                    removeBtn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        builderSections.splice(index, 1);
                        renderBuilderCanvas();
                        updateGenerateButton();
                    });
                    
                    // Drag to move within canvas
                    let isDragMoving = false;
                    let dragStartX, dragStartY, dragStartLeft, dragStartTop;
                    
                    sectionEl.addEventListener('mousedown', (e) => {
                        if (e.target.classList.contains('remove-btn')) return;
                        isDragMoving = true;
                        const rect = pageBuilderCanvas.getBoundingClientRect();
                        dragStartX = e.clientX;
                        dragStartY = e.clientY;
                        dragStartLeft = section.defaultX || 0;
                        dragStartTop = section.defaultY || 0;
                        sectionEl.style.zIndex = '50';
                        sectionEl.classList.add('dragging');
                        e.preventDefault();
                    });
                    
                    document.addEventListener('mousemove', (e) => {
                        if (!isDragMoving) return;
                        const rect = pageBuilderCanvas.getBoundingClientRect();
                        const dxPct = ((e.clientX - dragStartX) / rect.width) * 100;
                        const dyPct = ((e.clientY - dragStartY) / rect.height) * 100;
                        
                        let newX = dragStartLeft + dxPct;
                        let newY = dragStartTop + dyPct;
                        
                        // Clamp to canvas bounds
                        const w = section.defaultWidth || 100;
                        const h = section.defaultHeight || 20;
                        newX = Math.max(0, Math.min(newX, 100 - w));
                        newY = Math.max(0, Math.min(newY, 100 - h));
                        
                        sectionEl.style.left = newX + '%';
                        sectionEl.style.top = newY + '%';
                    });
                    
                    document.addEventListener('mouseup', () => {
                        if (isDragMoving) {
                            isDragMoving = false;
                            sectionEl.style.zIndex = '';
                            sectionEl.classList.remove('dragging');
                            
                            // Update data from final position
                            section.defaultX = Math.round(parseFloat(sectionEl.style.left));
                            section.defaultY = Math.round(parseFloat(sectionEl.style.top));
                            renderBuilderCanvas(); // Re-render to update all positions
                        }
                    });
                    
                    pageBuilderCanvas.appendChild(sectionEl);
                });
            }
            
            function clearBuilderCanvas() {
                builderSections = [];
                const existingSections = pageBuilderCanvas.querySelectorAll('.canvas-section');
                existingSections.forEach(s => s.remove());
                builderEmptyState.style.display = 'flex';
            }
            
            // Clear button handler
            if (builderClearBtn) {
                builderClearBtn.addEventListener('click', () => {
                    clearBuilderCanvas();
                    updateGenerateButton();
                });
            }
            
            // Column preset buttons
            const presetBtns = document.querySelectorAll('.builder-preset-btn');
            presetBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    const preset = btn.dataset.preset;
                    builderSections = [];
                    
                    if (preset === '1col') {
                        builderSections = [
                            { type: 'title', name: 'Title', icon: '📄', defaultHeight: 10, defaultWidth: 100, defaultX: 0, defaultY: 0 },
                            { type: 'paragraph', name: 'Content', icon: '📝', defaultHeight: 90, defaultWidth: 100, defaultX: 0, defaultY: 10 }
                        ];
                    } else if (preset === '2col') {
                        builderSections = [
                            { type: 'title', name: 'Title', icon: '📄', defaultHeight: 10, defaultWidth: 100, defaultX: 0, defaultY: 0 },
                            { type: 'paragraph', name: 'Left', icon: '📝', defaultHeight: 90, defaultWidth: 48, defaultX: 1, defaultY: 10 },
                            { type: 'paragraph', name: 'Right', icon: '📝', defaultHeight: 90, defaultWidth: 48, defaultX: 51, defaultY: 10 }
                        ];
                    } else if (preset === '3col') {
                        builderSections = [
                            { type: 'title', name: 'Title', icon: '📄', defaultHeight: 10, defaultWidth: 100, defaultX: 0, defaultY: 0 },
                            { type: 'paragraph', name: 'Col 1', icon: '📝', defaultHeight: 90, defaultWidth: 31, defaultX: 1, defaultY: 10 },
                            { type: 'paragraph', name: 'Col 2', icon: '📝', defaultHeight: 90, defaultWidth: 31, defaultX: 34, defaultY: 10 },
                            { type: 'paragraph', name: 'Col 3', icon: '📝', defaultHeight: 90, defaultWidth: 31, defaultX: 67, defaultY: 10 }
                        ];
                    }
                    
                    renderBuilderCanvas();
                    updateGenerateButton();
                });
                
                btn.addEventListener('mouseenter', () => { btn.style.transform = 'translateY(-1px)'; });
                btn.addEventListener('mouseleave', () => { btn.style.transform = ''; });
            });
            
            // Keep old functions for compatibility
            function getDropIndex(clientY) { return builderSections.length; }
            function reorderCanvasSection(fromIndex, toIndex) {
                if (fromIndex === toIndex) return;
                
                const section = builderSections.splice(fromIndex, 1)[0];
                builderSections.splice(toIndex, 0, section);
                renderBuilderCanvas();
            }
            
            function updateGenerateButton() {
                if (!templateModalGenerate) {
                    console.error('templateModalGenerate button not found!');
                    return;
                }
                
                const activePanel = templateModal.querySelector('.signature-panel.active');
                console.log('Active panel element:', activePanel);
                console.log('Active panel classList:', activePanel ? activePanel.classList.toString() : 'null');
                console.log('Active panel dataset:', activePanel ? activePanel.dataset : 'null');
                
                const isPrefabsTab = activePanel && activePanel.dataset.templatePanel === 'prefabs';
                const isBuilderTab = activePanel && activePanel.dataset.templatePanel === 'builder';
                
                console.log('updateGenerateButton:', { 
                    isPrefabsTab, 
                    isBuilderTab, 
                    builderSectionsCount: builderSections.length, 
                    selectedLayout,
                    activePanelDataset: activePanel ? activePanel.dataset.templatePanel : 'none'
                });
                
                let shouldEnable = false;
                
                if (isPrefabsTab) {
                    shouldEnable = !!selectedLayout;
                } else if (isBuilderTab) {
                    shouldEnable = builderSections.length > 0;
                }
                
                templateModalGenerate.disabled = !shouldEnable;
                
                console.log('Button state:', shouldEnable ? 'ENABLED' : 'DISABLED', 'disabled attr:', templateModalGenerate.disabled);
            }
            
            // Generate page from template
            if (templateModalGenerate) {
                templateModalGenerate.addEventListener('click', async () => {
                    console.log('Generate button clicked!');
                    
                    const activePanel = templateModal.querySelector('.signature-panel.active');
                    const isPrefabsTab = activePanel && activePanel.dataset.templatePanel === 'prefabs';
                    const isBuilderTab = activePanel && activePanel.dataset.templatePanel === 'builder';
                    
                    console.log('Generate click - Active tab:', { isPrefabsTab, isBuilderTab });
                    
                    // Determine which sections to use
                    let sectionsToRender = [];
                    if (isPrefabsTab) {
                        sectionsToRender = templateSections;
                        console.log('Using prefab sections:', sectionsToRender.length);
                    } else if (isBuilderTab) {
                        sectionsToRender = computeSectionPositions(builderSections.map(s => ({
                            ...s,
                            height: s.defaultHeight
                        })));
                        console.log('Using builder sections:', sectionsToRender.length);
                    }
                    
                    if (sectionsToRender.length === 0) {
                        setStatus('Please add sections to generate a page', 'err');
                        return;
                    }
                    
                    console.log('Generating page from sections:', sectionsToRender);
                    
                    // Get the dimensions from the first page of the PDF
                    if (!pdfjsDocument) {
                        await loadPdf();
                    }
                    const firstPage = await pdfjsDocument.getPage(1);
                    const viewport = firstPage.getViewport({ scale: currentScale });
                    const pageWidth = viewport.width;
                    const pageHeight = viewport.height;
                    
                    // Create a new page canvas with sections
                    const canvas = document.createElement('canvas');
                    canvas.width = pageWidth;
                    canvas.height = pageHeight;
                    const ctx = canvas.getContext('2d');
                    
                    // White background
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, pageWidth, pageHeight);
                    
                    // Draw sections using their computed positions
                    sectionsToRender.forEach((section, i) => {
                        const sectionHeight = pageHeight * (section.height / 100);
                        const sectionWidth = pageWidth * ((section.defaultWidth || 100) / 100);
                        const sectionX = pageWidth * ((section.defaultX || 0) / 100);
                        const sectionY = pageHeight * ((section.defaultY || 0) / 100);
                        
                        // Draw section border with different colors
                        const colors = ['#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981'];
                        ctx.strokeStyle = colors[i % colors.length];
                        ctx.lineWidth = 2;
                        ctx.strokeRect(sectionX + 10, sectionY + 10, sectionWidth - 20, sectionHeight - 20);
                        
                        // Draw section icon and name
                        ctx.fillStyle = '#000000';
                        ctx.font = 'bold 24px sans-serif';
                        ctx.textAlign = 'left';
                        ctx.fillText(section.icon || '', sectionX + 30, sectionY + 50);
                        
                        ctx.font = 'bold 18px sans-serif';
                        ctx.fillText(section.name || section.type, sectionX + 70, sectionY + 50);
                        
                        // Draw section dimensions
                        ctx.font = '14px sans-serif';
                        ctx.fillStyle = '#000000';
                        ctx.fillText(`H: ${section.height}% W: ${section.defaultWidth || 100}%`, sectionX + 30, sectionY + 80);
                    });
                    
                    // Add the generated page to the AI viewer
                    const aiViewer = document.getElementById('ai-viewer');
                    const wrapper = document.createElement('div');
                    wrapper.className = 'page generated-page';
                    wrapper.style.border = '3px solid #10b981';
                    wrapper.style.position = 'relative';
                    wrapper.appendChild(canvas);
                    
                    // Store sections data for regeneration
                    wrapper.sectionsData = sectionsToRender;
                    wrapper.pageWidth = pageWidth;
                    wrapper.pageHeight = pageHeight;
                    
                    // Function to create section overlay with full functionality
                    function createSectionOverlay(wrapper, section, i, sectionsToRender, pageWidth, pageHeight) {
                        const sectionHeight = pageHeight * (section.height / 100);
                        const sectionWidth = pageWidth * ((section.defaultWidth || 100) / 100);
                        const sectionX = pageWidth * ((section.defaultX || 0) / 100);
                        const sectionY = pageHeight * ((section.defaultY || 0) / 100);
                        const MIN_SIZE = 30; // minimum px for width/height
                        
                        const sectionOverlay = document.createElement('div');
                        sectionOverlay.className = 'section-overlay';
                        sectionOverlay.style.cssText = 'position: absolute; left: ' + sectionX + 'px; top: ' + sectionY + 'px; width: ' + sectionWidth + 'px; height: ' + sectionHeight + 'px; cursor: move; border: 2px solid transparent; transition: border-color 0.2s;';
                        sectionOverlay.dataset.sectionIndex = i;
                        
                        // Add controls panel with delete button
                        const controlsPanel = document.createElement('div');
                        controlsPanel.className = 'section-overlay-controls';
                        controlsPanel.style.cssText = 'position: absolute; top: 5px; right: 5px; background: rgba(17, 24, 39, 0.95); border: 1px solid rgba(255,255,255,0.2); border-radius: 6px; padding: 8px; display: none; gap: 6px; align-items: center; z-index: 10; pointer-events: auto;';
                        
                        const deleteBtnHtml = '<button class="delete-section-btn" style="background: rgba(239, 68, 68, 0.9); border: none; color: white; border-radius: 4px; padding: 4px 8px; cursor: pointer; font-size: 11px; font-weight: 600; transition: all 0.2s;" title="Delete section">&#128465;&#65039;</button>';
                        const xInputHtml = '<span style="color: #9ca3af; font-size: 11px; font-weight: 600;">X:</span><input type="number" class="overlay-x-input" value="' + Math.round(section.defaultX || 0) + '" min="0" max="100" style="width: 50px; padding: 3px 5px; border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; background: rgba(255,255,255,0.1); color: white; font-size: 11px;">';
                        const yInputHtml = '<span style="color: #9ca3af; font-size: 11px; font-weight: 600;">Y:</span><input type="number" class="overlay-y-input" value="' + Math.round(section.defaultY || 0) + '" min="0" max="100" style="width: 50px; padding: 3px 5px; border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; background: rgba(255,255,255,0.1); color: white; font-size: 11px;">';
                        const percentHtml = '<span style="color: #9ca3af; font-size: 11px;">%</span>';
                        controlsPanel.innerHTML = deleteBtnHtml + xInputHtml + yInputHtml + percentHtml;
                        
                        sectionOverlay.appendChild(controlsPanel);
                        
                        // Delete button handler
                        const deleteBtn = controlsPanel.querySelector('.delete-section-btn');
                        deleteBtn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            if (confirm('Delete this section?')) {
                                sectionsToRender.splice(i, 1);
                                wrapper.sectionsData = sectionsToRender;
                                sectionOverlay.remove();
                                regenerateCanvas(wrapper);
                                
                                const allOverlays = wrapper.querySelectorAll('.section-overlay');
                                allOverlays.forEach(o => o.remove());
                                sectionsToRender.forEach((sec, idx) => {
                                    const newOverlay = createSectionOverlay(wrapper, sec, idx, sectionsToRender, pageWidth, pageHeight);
                                    wrapper.appendChild(newOverlay);
                                });
                                
                                setStatus('Section deleted', 'ok');
                            }
                        });
                        deleteBtn.addEventListener('mouseenter', () => { deleteBtn.style.background = '#ef4444'; });
                        deleteBtn.addEventListener('mouseleave', () => { deleteBtn.style.background = 'rgba(239, 68, 68, 0.9)'; });
                        
                        // ===== ALL 8 RESIZE HANDLES =====
                        const handleDefs = [
                            { name: 'n',  css: 'position:absolute; top:-4px; left:10px; right:10px; height:8px; cursor:ns-resize; z-index:11;' },
                            { name: 's',  css: 'position:absolute; bottom:-4px; left:10px; right:10px; height:8px; cursor:ns-resize; z-index:11;' },
                            { name: 'e',  css: 'position:absolute; right:-4px; top:10px; bottom:10px; width:8px; cursor:ew-resize; z-index:11;' },
                            { name: 'w',  css: 'position:absolute; left:-4px; top:10px; bottom:10px; width:8px; cursor:ew-resize; z-index:11;' },
                            { name: 'ne', css: 'position:absolute; top:-5px; right:-5px; width:12px; height:12px; cursor:nesw-resize; z-index:12; border-radius:50%;' },
                            { name: 'nw', css: 'position:absolute; top:-5px; left:-5px; width:12px; height:12px; cursor:nwse-resize; z-index:12; border-radius:50%;' },
                            { name: 'se', css: 'position:absolute; bottom:-5px; right:-5px; width:12px; height:12px; cursor:nwse-resize; z-index:12; border-radius:50%;' },
                            { name: 'sw', css: 'position:absolute; bottom:-5px; left:-5px; width:12px; height:12px; cursor:nesw-resize; z-index:12; border-radius:50%;' }
                        ];
                        
                        const handles = {};
                        handleDefs.forEach(def => {
                            const h = document.createElement('div');
                            h.className = 'resize-handle ' + def.name;
                            h.style.cssText = def.css + ' background: rgba(59,130,246,0); transition: background 0.2s;';
                            h.addEventListener('mouseenter', () => { h.style.background = 'rgba(59,130,246,0.5)'; });
                            h.addEventListener('mouseleave', () => { h.style.background = 'rgba(59,130,246,0)'; });
                            sectionOverlay.appendChild(h);
                            handles[def.name] = h;
                        });
                        
                        // ===== UNIFIED RESIZE STATE =====
                        let activeResize = null; // { handle, startMouseX, startMouseY, startLeft, startTop, startW, startH }
                        
                        Object.keys(handles).forEach(name => {
                            handles[name].addEventListener('mousedown', (e) => {
                                activeResize = {
                                    handle: name,
                                    startMouseX: e.clientX,
                                    startMouseY: e.clientY,
                                    startLeft: sectionOverlay.offsetLeft,
                                    startTop: sectionOverlay.offsetTop,
                                    startW: sectionOverlay.offsetWidth,
                                    startH: sectionOverlay.offsetHeight
                                };
                                e.stopPropagation();
                                e.preventDefault();
                            });
                        });
                        
                        document.addEventListener('mousemove', (e) => {
                            if (!activeResize) return;
                            const dx = e.clientX - activeResize.startMouseX;
                            const dy = e.clientY - activeResize.startMouseY;
                            const h = activeResize.handle;
                            
                            let newLeft = activeResize.startLeft;
                            let newTop = activeResize.startTop;
                            let newW = activeResize.startW;
                            let newH = activeResize.startH;
                            
                            // East: expand width rightward
                            if (h === 'e' || h === 'ne' || h === 'se') {
                                newW = Math.max(MIN_SIZE, activeResize.startW + dx);
                            }
                            // West: move left edge, adjust width
                            if (h === 'w' || h === 'nw' || h === 'sw') {
                                const maxDx = activeResize.startW - MIN_SIZE;
                                const clampedDx = Math.min(dx, maxDx);
                                newLeft = activeResize.startLeft + clampedDx;
                                newW = activeResize.startW - clampedDx;
                            }
                            // South: expand height downward
                            if (h === 's' || h === 'se' || h === 'sw') {
                                newH = Math.max(MIN_SIZE, activeResize.startH + dy);
                            }
                            // North: move top edge, adjust height
                            if (h === 'n' || h === 'ne' || h === 'nw') {
                                const maxDy = activeResize.startH - MIN_SIZE;
                                const clampedDy = Math.min(dy, maxDy);
                                newTop = activeResize.startTop + clampedDy;
                                newH = activeResize.startH - clampedDy;
                            }
                            
                            // Clamp to page boundaries
                            if (newLeft < 0) { newW += newLeft; newLeft = 0; }
                            if (newTop < 0) { newH += newTop; newTop = 0; }
                            if (newLeft + newW > pageWidth) newW = pageWidth - newLeft;
                            if (newTop + newH > pageHeight) newH = pageHeight - newTop;
                            newW = Math.max(MIN_SIZE, newW);
                            newH = Math.max(MIN_SIZE, newH);
                            
                            sectionOverlay.style.left = newLeft + 'px';
                            sectionOverlay.style.top = newTop + 'px';
                            sectionOverlay.style.width = newW + 'px';
                            sectionOverlay.style.height = newH + 'px';
                        });
                        
                        document.addEventListener('mouseup', () => {
                            if (activeResize) {
                                // Update section data from final overlay position/size
                                const finalLeft = parseFloat(sectionOverlay.style.left);
                                const finalTop = parseFloat(sectionOverlay.style.top);
                                const finalW = parseFloat(sectionOverlay.style.width);
                                const finalH = parseFloat(sectionOverlay.style.height);
                                
                                sectionsToRender[i].defaultX = Math.round((finalLeft / pageWidth) * 100);
                                sectionsToRender[i].defaultY = Math.round((finalTop / pageHeight) * 100);
                                sectionsToRender[i].defaultWidth = Math.round((finalW / pageWidth) * 100);
                                sectionsToRender[i].height = Math.round((finalH / pageHeight) * 100);
                                
                                // Update inputs
                                xInput.value = sectionsToRender[i].defaultX;
                                yInput.value = sectionsToRender[i].defaultY;
                                
                                activeResize = null;
                                regenerateCanvas(wrapper);
                                saveSectionsToDatabase(sectionsToRender, pageWidth, pageHeight);
                            }
                        });
                        
                        // Show/hide controls on hover
                        sectionOverlay.addEventListener('mouseenter', () => {
                            sectionOverlay.style.borderColor = 'rgba(59, 130, 246, 0.8)';
                            controlsPanel.style.display = 'flex';
                        });
                        sectionOverlay.addEventListener('mouseleave', () => {
                            if (!activeResize) {
                                sectionOverlay.style.borderColor = 'transparent';
                                controlsPanel.style.display = 'none';
                            }
                        });
                        
                        // Handle input changes
                        const xInput = controlsPanel.querySelector('.overlay-x-input');
                        const yInput = controlsPanel.querySelector('.overlay-y-input');
                        
                        xInput.addEventListener('input', (e) => {
                            const newX = Math.max(0, Math.min(100, parseInt(e.target.value) || 0));
                            const newLeft = (newX / 100) * pageWidth;
                            // Clamp so section stays on page
                            const clampedLeft = Math.min(newLeft, pageWidth - parseFloat(sectionOverlay.style.width));
                            sectionOverlay.style.left = Math.max(0, clampedLeft) + 'px';
                            sectionsToRender[i].defaultX = Math.round((Math.max(0, clampedLeft) / pageWidth) * 100);
                            regenerateCanvas(wrapper);
                            saveSectionsToDatabase(sectionsToRender, pageWidth, pageHeight);
                        });
                        
                        yInput.addEventListener('input', (e) => {
                            const newY = Math.max(0, Math.min(100, parseInt(e.target.value) || 0));
                            const newTop = (newY / 100) * pageHeight;
                            const clampedTop = Math.min(newTop, pageHeight - parseFloat(sectionOverlay.style.height));
                            sectionOverlay.style.top = Math.max(0, clampedTop) + 'px';
                            sectionsToRender[i].defaultY = Math.round((Math.max(0, clampedTop) / pageHeight) * 100);
                            regenerateCanvas(wrapper);
                            saveSectionsToDatabase(sectionsToRender, pageWidth, pageHeight);
                        });
                        
                        // ===== DRAG FUNCTIONALITY WITH PAGE CLAMPING =====
                        let isDragging = false;
                        let startX, startY, offsetX, offsetY;
                        
                        sectionOverlay.addEventListener('mousedown', (e) => {
                            if (e.target.tagName === 'INPUT' || e.target.tagName === 'BUTTON') return;
                            if (e.target.classList.contains('resize-handle')) return;
                            
                            isDragging = true;
                            startX = e.clientX;
                            startY = e.clientY;
                            offsetX = sectionOverlay.offsetLeft;
                            offsetY = sectionOverlay.offsetTop;
                            sectionOverlay.style.zIndex = '1000';
                            sectionOverlay.style.borderColor = 'rgba(59, 130, 246, 1)';
                            e.preventDefault();
                        });
                        
                        document.addEventListener('mousemove', (e) => {
                            if (!isDragging) return;
                            const deltaX = e.clientX - startX;
                            const deltaY = e.clientY - startY;
                            let newLeft = offsetX + deltaX;
                            let newTop = offsetY + deltaY;
                            
                            // Clamp to page boundaries
                            const w = sectionOverlay.offsetWidth;
                            const h = sectionOverlay.offsetHeight;
                            newLeft = Math.max(0, Math.min(newLeft, pageWidth - w));
                            newTop = Math.max(0, Math.min(newTop, pageHeight - h));
                            
                            sectionOverlay.style.left = newLeft + 'px';
                            sectionOverlay.style.top = newTop + 'px';
                            
                            const newXPct = Math.round((newLeft / pageWidth) * 100);
                            const newYPct = Math.round((newTop / pageHeight) * 100);
                            xInput.value = newXPct;
                            yInput.value = newYPct;
                        });
                        
                        document.addEventListener('mouseup', () => {
                            if (isDragging) {
                                isDragging = false;
                                sectionOverlay.style.zIndex = '';
                                sectionOverlay.style.borderColor = 'transparent';
                                
                                const newX = (parseFloat(sectionOverlay.style.left) / pageWidth) * 100;
                                const newY = (parseFloat(sectionOverlay.style.top) / pageHeight) * 100;
                                sectionsToRender[i].defaultX = Math.round(newX);
                                sectionsToRender[i].defaultY = Math.round(newY);
                                
                                regenerateCanvas(wrapper);
                                saveSectionsToDatabase(sectionsToRender, pageWidth, pageHeight);
                            }
                        });
                        
                        return sectionOverlay;
                    }
                    
                    // Create draggable section overlays
                    sectionsToRender.forEach((section, i) => {
                        const overlay = createSectionOverlay(wrapper, section, i, sectionsToRender, pageWidth, pageHeight);
                        wrapper.appendChild(overlay);
                    });
                    
                    // Add menu bar to the right of the page
                    const menu = document.createElement('div');
                    menu.className = 'generated-page-menu';
                    const unlockIcon = String.fromCodePoint(0x1F513);
                    const plusIcon = String.fromCodePoint(0x2795);
                    const trashIcon = String.fromCodePoint(0x1F5D1, 0xFE0F);
                    menu.innerHTML = `
                        <button class="lock-sections-btn" title="Lock/Unlock sections">
                            <span>${unlockIcon}</span> Unlock
                        </button>
                        <button class="add-section-btn" title="Add section to page">
                            <span>${plusIcon}</span> Section
                        </button>
                        <button class="delete-page-btn" title="Delete this page">
                            <span>${trashIcon}</span> Delete
                        </button>
                    `;
                    wrapper.appendChild(menu);
                    
                    // Track locked state
                    let sectionsLocked = false;
                    
                    // Add lock handler
                    const lockBtn = menu.querySelector('.lock-sections-btn');
                    lockBtn.addEventListener('click', () => {
                        sectionsLocked = !sectionsLocked;
                        const overlays = wrapper.querySelectorAll('.section-overlay');
                        
                        overlays.forEach(overlay => {
                            if (sectionsLocked) {
                                overlay.style.pointerEvents = 'none';
                                overlay.style.opacity = '0.5';
                            } else {
                                overlay.style.pointerEvents = 'auto';
                                overlay.style.opacity = '1';
                            }
                        });
                        
                        if (sectionsLocked) {
                            lockBtn.innerHTML = '<span>🔒</span> Locked';
                            lockBtn.classList.add('locked');
                            setStatus('Sections locked', 'ok');
                        } else {
                            lockBtn.innerHTML = '<span>🔓</span> Unlock';
                            lockBtn.classList.remove('locked');
                            setStatus('Sections unlocked', 'ok');
                        }
                    });
                    
                    // Add section handler
                    const addSectionBtn = menu.querySelector('.add-section-btn');
                    addSectionBtn.addEventListener('click', () => {
                        // Create and show modal
                        const addSectionModal = document.createElement('div');
                        addSectionModal.className = 'modal';
                        addSectionModal.style.display = 'flex';
                        addSectionModal.innerHTML = `
                            <div class="modal-content" style="max-width: 400px;">
                                <div class="modal-header">
                                    <span>Add Section</span>
                                    <button class="modal-close" type="button">×</button>
                                </div>
                                <div class="modal-body" style="padding: 20px;">
                                    <label style="display: block; font-weight: 600; margin-bottom: 12px;">Section Type</label>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                        <button class="section-type-btn" data-type="paragraph" style="padding: 20px; background: rgba(59, 130, 246, 0.1); border: 2px solid rgba(59, 130, 246, 0.3); border-radius: 8px; cursor: pointer; transition: all 0.2s;">
                                            <div style="font-size: 32px; margin-bottom: 8px;">📄</div>
                                            <div style="font-weight: 600;">Paragraph</div>
                                        </button>
                                        <button class="section-type-btn" data-type="title" style="padding: 20px; background: rgba(139, 92, 246, 0.1); border: 2px solid rgba(139, 92, 246, 0.3); border-radius: 8px; cursor: pointer; transition: all 0.2s;">
                                            <div style="font-size: 32px; margin-bottom: 8px;">📌</div>
                                            <div style="font-weight: 600;">Title</div>
                                        </button>
                                        <button class="section-type-btn" data-type="chart" style="padding: 20px; background: rgba(236, 72, 153, 0.1); border: 2px solid rgba(236, 72, 153, 0.3); border-radius: 8px; cursor: pointer; transition: all 0.2s;">
                                            <div style="font-size: 32px; margin-bottom: 8px;">📊</div>
                                            <div style="font-weight: 600;">Chart</div>
                                        </button>
                                        <button class="section-type-btn" data-type="graphic" style="padding: 20px; background: rgba(245, 158, 11, 0.1); border: 2px solid rgba(245, 158, 11, 0.3); border-radius: 8px; cursor: pointer; transition: all 0.2s;">
                                            <div style="font-size: 32px; margin-bottom: 8px;">🖼️</div>
                                            <div style="font-weight: 600;">Image</div>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        `;
                        document.body.appendChild(addSectionModal);
                        
                        // Add hover effects
                        const sectionTypeBtns = addSectionModal.querySelectorAll('.section-type-btn');
                        sectionTypeBtns.forEach(btn => {
                            btn.addEventListener('mouseenter', () => {
                                btn.style.transform = 'translateY(-2px)';
                                btn.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
                            });
                            btn.addEventListener('mouseleave', () => {
                                btn.style.transform = '';
                                btn.style.boxShadow = '';
                            });
                            btn.addEventListener('click', () => {
                                const sectionType = btn.dataset.type;
                                
                                // Add new section to the page
                                const newSection = {
                                    type: sectionType,
                                    height: 20,
                                    defaultX: 10,
                                    defaultY: sectionsToRender.length * 20,
                                    defaultWidth: 80
                                };
                                
                                sectionsToRender.push(newSection);
                                wrapper.sectionsData = sectionsToRender;
                                
                                // Close modal
                                addSectionModal.remove();
                                setStatus(`Added ${sectionType} section`, 'ok');
                                
                                // Regenerate the canvas with new section
                                regenerateCanvas(wrapper);
                                
                                // Remove existing overlays
                                const existingOverlays = wrapper.querySelectorAll('.section-overlay');
                                existingOverlays.forEach(o => o.remove());
                                
                                // Recreate overlays for all sections using the helper function
                                sectionsToRender.forEach((sec, idx) => {
                                    const newOverlay = createSectionOverlay(wrapper, sec, idx, sectionsToRender, pageWidth, pageHeight);
                                    wrapper.appendChild(newOverlay);
                                });
                            });
                        });
                        
                        // Close modal handler
                        const closeBtn = addSectionModal.querySelector('.modal-close');
                        closeBtn.addEventListener('click', () => addSectionModal.remove());
                        addSectionModal.addEventListener('click', (e) => {
                            if (e.target === addSectionModal) addSectionModal.remove();
                        });
                    });
                    
                    // Add delete handler
                    const deleteBtn = menu.querySelector('.delete-page-btn');
                    deleteBtn.addEventListener('click', () => {
                        if (confirm('Delete this generated page?')) {
                            wrapper.remove();
                            setStatus('Page deleted', 'ok');
                        }
                    });
                    
                    aiViewer.insertBefore(wrapper, aiViewer.firstChild);
                    
                    // Save template structure to localStorage
                    saveTemplateToLocalStorage(sectionsToRender, pageWidth, pageHeight);
                    
                    // Save sections to database
                    saveSectionsToDatabase(sectionsToRender, pageWidth, pageHeight);
                    
                    // Scroll to the new page
                    wrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    
                    setStatus('Template page generated successfully!', 'ok');
                    closeTemplateModal();
                });
            }
            
            // Function to regenerate canvas when sections are moved
            function regenerateCanvas(wrapper) {
                const sectionsData = wrapper.sectionsData;
                const pageWidth = wrapper.pageWidth;
                const pageHeight = wrapper.pageHeight;
                const generatedContent = wrapper.generatedContent; // Get stored content
                const generatedImages = wrapper.generatedImages || []; // Get stored images
                
                // Get the canvas element
                const canvas = wrapper.querySelector('canvas');
                const ctx = canvas.getContext('2d');
                
                // Clear and redraw
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, pageWidth, pageHeight);
                
                // Draw sections with updated positions
                sectionsData.forEach((section, i) => {
                    const sectionHeight = pageHeight * (section.height / 100);
                    const sectionWidth = pageWidth * ((section.defaultWidth || 100) / 100);
                    const sectionX = pageWidth * ((section.defaultX || 0) / 100);
                    const sectionY = pageHeight * ((section.defaultY || 0) / 100);
                    
                    // Draw section border with different colors
                    const colors = ['#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981'];
                    ctx.strokeStyle = colors[i % colors.length];
                    ctx.lineWidth = 2;
                    ctx.strokeRect(sectionX + 10, sectionY + 10, sectionWidth - 20, sectionHeight - 20);
                    
                    // Check if this is an image section
                    const isImageSection = ['image', 'graphic', 'chart'].includes(section.type?.toLowerCase());
                    const generatedImage = generatedImages.find(img => img.section_number === i);
                    
                    // Check if there's generated content for this section
                    if (isImageSection && generatedImage && generatedImage.image_id) {
                        // Fetch and render image from database
                        fetch(`/api/ai-images/${generatedImage.image_id}`)
                            .then(response => response.json())
                            .then(imageData => {
                                if (imageData.image_data && imageData.storage_type === 'base64') {
                                    const img = new Image();
                                    img.onload = function() {
                                        const padding = 20;
                                        const maxWidth = sectionWidth - padding * 2;
                                        const maxHeight = sectionHeight - padding * 2;
                                        
                                        let drawWidth = img.width;
                                        let drawHeight = img.height;
                                        
                                        const scale = Math.min(maxWidth / drawWidth, maxHeight / drawHeight, 1);
                                        drawWidth *= scale;
                                        drawHeight *= scale;
                                        
                                        const x = sectionX + (sectionWidth - drawWidth) / 2;
                                        const y = sectionY + (sectionHeight - drawHeight) / 2;
                                        
                                        ctx.drawImage(img, x, y, drawWidth, drawHeight);
                                    };
                                    img.src = `data:image/png;base64,${imageData.image_data}`;
                                }
                            })
                            .catch(error => console.error('Failed to load image:', error));
                    } else if (generatedContent) {
                        // Handle both array and object formats
                        const contentArray = Array.isArray(generatedContent) ? generatedContent : 
                            (generatedContent.sections || []);
                        
                        const generatedSection = contentArray.find(gs => 
                            gs.section_number === (i + 1) || 
                            gs.type === section.type
                        );
                        
                        if (generatedSection && generatedSection.content) {
                            // Render the generated content
                            ctx.fillStyle = '#000000';
                            
                            // Adjust font size based on section type and height
                            let fontSize = 16;
                            if (section.type === 'title') {
                                fontSize = Math.min(32, sectionHeight / 3);
                            } else if (section.type === 'paragraph') {
                                fontSize = Math.min(16, sectionHeight / 10);
                            }
                            
                            ctx.font = `${fontSize}px sans-serif`;
                            ctx.textAlign = 'left';
                            
                            // Word wrap and render text within section bounds
                            const maxWidth = sectionWidth - 60; // Padding
                            const lineHeight = fontSize * 1.4;
                            const maxLines = Math.floor((sectionHeight - 40) / lineHeight);
                            
                            const words = generatedSection.content.split(' ');
                            let lines = [];
                            let currentLine = '';
                            
                            for (let word of words) {
                                const testLine = currentLine + (currentLine ? ' ' : '') + word;
                                const metrics = ctx.measureText(testLine);
                                
                                if (metrics.width > maxWidth && currentLine) {
                                    lines.push(currentLine);
                                    currentLine = word;
                                } else {
                                    currentLine = testLine;
                                }
                                
                                if (lines.length >= maxLines) break;
                            }
                            if (currentLine && lines.length < maxLines) {
                                lines.push(currentLine);
                            }
                            
                            // Draw the text lines
                            let textY = sectionY + 30 + fontSize;
                            lines.forEach((line, lineIndex) => {
                                if (textY + lineHeight <= sectionY + sectionHeight - 20) {
                                    ctx.fillText(line, sectionX + 30, textY);
                                    textY += lineHeight;
                                }
                            });
                            
                            return; // Skip drawing placeholder elements
                        }
                    }
                    
                    // Draw section icon and name (only if no generated content)
                    ctx.fillStyle = '#000000';
                    ctx.font = 'bold 24px sans-serif';
                    ctx.textAlign = 'left';
                    ctx.fillText(section.icon || '', sectionX + 30, sectionY + 50);
                    
                    ctx.font = 'bold 18px sans-serif';
                    ctx.fillText(section.name || section.type, sectionX + 70, sectionY + 50);
                    
                    // Draw section dimensions
                    ctx.font = '14px sans-serif';
                    ctx.fillStyle = '#000000';
                    ctx.fillText(`H: ${section.height}% W: ${section.defaultWidth || 100}%`, sectionX + 30, sectionY + 80);
                });
            }
            
            // AI mode bar buttons - connect to same save/clear functionality
            const aiSaveBtn = document.getElementById('ai-save-btn');
            const aiClearBtn = document.getElementById('ai-clear-btn');
            const savePdfBtn = document.getElementById('save-btn');
            const clearAllBtn = document.getElementById('clear-btn');
            
            if (aiSaveBtn && savePdfBtn) {
                aiSaveBtn.addEventListener('click', () => savePdfBtn.click());
            }
            if (aiClearBtn && clearAllBtn) {
                aiClearBtn.addEventListener('click', () => clearAllBtn.click());
            }
            
            // Initialize zoom on page load (without re-rendering, just update the label)
            // Mobile gets 100% of base scale (50%), desktop gets 130% of base scale (169%)
            const initialZoomMultiplier = isMobile ? 1.0 : 1.3;
            currentScale = baseScale * initialZoomMultiplier;
            zoomLabel.textContent = Math.round(initialZoomMultiplier * 100) + '%';
            
            // ===== CUSTOMIZE PROMPT MODAL =====
            const customizePromptBtn = document.getElementById('customize-prompt-btn');
            const promptModal = document.getElementById('customize-prompt-modal');
            const promptModalClose = document.getElementById('prompt-modal-close');
            const promptModalCancel = document.getElementById('prompt-modal-cancel');
            const promptModalSave = document.getElementById('prompt-modal-save');
            const promptModalReset = document.getElementById('prompt-modal-reset');
            
            const promptStyleInput = document.getElementById('prompt-style');
            const promptQualityInput = document.getElementById('prompt-quality');
            const promptAdditionalInput = document.getElementById('prompt-additional');
            const promptPreview = document.getElementById('prompt-preview');
            
            // Default prompt settings
            const defaultPromptSettings = {
                style: 'modern and professional',
                quality: 'high-quality, photorealistic',
                additional: ''
            };
            
            // Load saved settings from localStorage or use defaults
            let promptSettings = JSON.parse(localStorage.getItem('aiPromptSettings')) || { ...defaultPromptSettings };
            
            // Function to update the preview
            function updatePromptPreview() {
                const style = promptStyleInput.value.trim() || defaultPromptSettings.style;
                const quality = promptQualityInput.value.trim() || defaultPromptSettings.quality;
                const additional = promptAdditionalInput.value.trim();
                
                let preview = `Generate a professional [type] for a document about: [user prompt]. Section name: [section name]. Image dimensions: [width]px × [height]px. The image should be ${quality}, and directly relevant to the content. Style: ${style}.`;
                
                if (additional) {
                    preview += ` ${additional}`;
                }
                
                promptPreview.textContent = preview;
            }
            
            // Load settings into form
            function loadPromptSettings() {
                promptStyleInput.value = promptSettings.style;
                promptQualityInput.value = promptSettings.quality;
                promptAdditionalInput.value = promptSettings.additional || '';
                updatePromptPreview();
            }
            
            // Open customize prompt modal
            if (customizePromptBtn) {
                customizePromptBtn.addEventListener('click', () => {
                    loadPromptSettings();
                    promptModal.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                });
            }
            
            // Close modal
            const closePromptModal = () => {
                promptModal.style.display = 'none';
                document.body.style.overflow = '';
            };
            
            if (promptModalClose) {
                promptModalClose.addEventListener('click', closePromptModal);
            }
            if (promptModalCancel) {
                promptModalCancel.addEventListener('click', closePromptModal);
            }
            
            // Update preview on input
            promptStyleInput.addEventListener('input', updatePromptPreview);
            promptQualityInput.addEventListener('input', updatePromptPreview);
            promptAdditionalInput.addEventListener('input', updatePromptPreview);
            
            // Reset to defaults
            if (promptModalReset) {
                promptModalReset.addEventListener('click', () => {
                    promptStyleInput.value = defaultPromptSettings.style;
                    promptQualityInput.value = defaultPromptSettings.quality;
                    promptAdditionalInput.value = '';
                    updatePromptPreview();
                });
            }
            
            // Save settings
            if (promptModalSave) {
                promptModalSave.addEventListener('click', () => {
                    promptSettings = {
                        style: promptStyleInput.value.trim() || defaultPromptSettings.style,
                        quality: promptQualityInput.value.trim() || defaultPromptSettings.quality,
                        additional: promptAdditionalInput.value.trim()
                    };
                    
                    // Save to localStorage
                    localStorage.setItem('aiPromptSettings', JSON.stringify(promptSettings));
                    
                    setStatus('Prompt settings saved successfully!', 'ok');
                    closePromptModal();
                });
            }
            
            // ===== END CUSTOMIZE PROMPT MODAL =====
            
            // AI Chat Functionality
            const aiChatInput = document.getElementById('ai-chat-input');
            const aiSendBtn = document.getElementById('ai-send-btn');
            const aiChatMessages = document.getElementById('ai-chat-messages');
            const aiAttachBtn = document.getElementById('ai-attach-btn');
            
            // Auto-resize textarea
            if (aiChatInput) {
                aiChatInput.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
                });
            }
            
            // Add message to chat
            function addChatMessage(text, isUser = true) {
                const messageDiv = document.createElement('div');
                messageDiv.className = `ai-chat-message ${isUser ? 'user' : 'bot'}`;
                
                const bubble = document.createElement('div');
                bubble.className = 'ai-message-bubble';
                bubble.textContent = text;
                
                messageDiv.appendChild(bubble);
                
                // Add copy button for bot messages
                if (!isUser) {
                    const copyBtn = document.createElement('div');
                    copyBtn.className = 'ai-message-copy';
                    copyBtn.innerHTML = `
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                        </svg>
                        Copy
                    `;
                    copyBtn.addEventListener('click', () => {
                        navigator.clipboard.writeText(text);
                        copyBtn.innerHTML = `
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                            Copied!
                        `;
                        setTimeout(() => {
                            copyBtn.innerHTML = `
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                </svg>
                                Copy
                            `;
                        }, 2000);
                    });
                    messageDiv.appendChild(copyBtn);
                }
                
                aiChatMessages.appendChild(messageDiv);
                aiChatMessages.scrollTop = aiChatMessages.scrollHeight;
            }
            
            // Render AI-generated content into sections
            function renderContentIntoSections(generatedSections, originalSections, generatedImages = []) {
                console.log('Rendering sections:', {generatedSections, originalSections, generatedImages});
                
                const aiViewer = document.getElementById('ai-viewer');
                const generatedPages = aiViewer ? aiViewer.querySelectorAll('.generated-page') : [];
                
                if (generatedPages.length === 0) return;
                
                // For each generated page, re-render with content
                generatedPages.forEach((page, pageIndex) => {
                    if (!page.sectionsData || !page.pageWidth || !page.pageHeight) return;
                    
                    // Store generated content on the page element
                    page.generatedContent = generatedSections;
                    page.generatedImages = generatedImages;
                    
                    const canvas = page.querySelector('canvas');
                    if (!canvas) return;
                    
                    const ctx = canvas.getContext('2d');
                    const pageWidth = page.pageWidth;
                    const pageHeight = page.pageHeight;
                    
                    // Clear and redraw white background
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, pageWidth, pageHeight);
                    
                    // Draw each section with generated content
                    page.sectionsData.forEach((section, sectionIndex) => {
                        const sectionHeight = pageHeight * (section.height / 100);
                        const sectionWidth = pageWidth * ((section.defaultWidth || 100) / 100);
                        const sectionX = pageWidth * ((section.defaultX || 0) / 100);
                        const sectionY = pageHeight * ((section.defaultY || 0) / 100);
                        
                        // Find the order number for this section from originalSections
                        const sectionOrder = originalSections[sectionIndex]?.order || sectionIndex;
                        
                        // Find matching generated content - match by section_number only
                        const generatedSection = generatedSections.find(gs => 
                            gs.section_number === (sectionIndex + 1)
                        );
                        
                        // Find matching generated image by order
                        const generatedImage = generatedImages.find(img => 
                            img.section_number === sectionOrder
                        );
                        
                        console.log(`Section ${sectionIndex}:`, {section, sectionOrder, generatedSection, generatedImage});
                        
                        // Draw section border
                        const colors = ['#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981'];
                        ctx.strokeStyle = colors[sectionIndex % colors.length];
                        ctx.lineWidth = 2;
                        ctx.strokeRect(sectionX + 10, sectionY + 10, sectionWidth - 20, sectionHeight - 20);
                        
                        // Check if this is an image section
                        const isImageSection = ['image', 'graphic', 'chart'].includes(section.type?.toLowerCase());
                        
                        if (isImageSection && generatedImage && generatedImage.image_data && generatedImage.storage_type === 'base64') {
                            // Render image
                            const img = new Image();
                            img.onload = function() {
                                // Calculate dimensions to fit within section
                                const padding = 20;
                                const maxWidth = sectionWidth - padding * 2;
                                const maxHeight = sectionHeight - padding * 2;
                                
                                let drawWidth = img.width;
                                let drawHeight = img.height;
                                
                                // Scale to fit
                                const scale = Math.min(maxWidth / drawWidth, maxHeight / drawHeight, 1);
                                drawWidth *= scale;
                                drawHeight *= scale;
                                
                                // Center the image
                                const x = sectionX + (sectionWidth - drawWidth) / 2;
                                const y = sectionY + (sectionHeight - drawHeight) / 2;
                                
                                ctx.drawImage(img, x, y, drawWidth, drawHeight);
                            };
                            img.src = `data:image/png;base64,${generatedImage.image_data}`;
                        } else if (generatedSection && generatedSection.content) {
                            // Render the generated text content
                            ctx.fillStyle = '#000000';
                            
                            // Adjust font size based on section type and height
                            let fontSize = 16;
                            if (section.type === 'title') {
                                fontSize = Math.min(32, sectionHeight / 3);
                            } else if (section.type === 'paragraph') {
                                fontSize = Math.min(16, sectionHeight / 10);
                            }
                            
                            ctx.font = `${fontSize}px sans-serif`;
                            ctx.textAlign = 'left';
                            
                            // Word wrap and render text within section bounds
                            const maxWidth = sectionWidth - 60; // Padding
                            const lineHeight = fontSize * 1.4;
                            const maxLines = Math.floor((sectionHeight - 40) / lineHeight);
                            
                            const words = generatedSection.content.split(' ');
                            let lines = [];
                            let currentLine = '';
                            
                            for (let word of words) {
                                const testLine = currentLine + (currentLine ? ' ' : '') + word;
                                const metrics = ctx.measureText(testLine);
                                
                                if (metrics.width > maxWidth && currentLine) {
                                    lines.push(currentLine);
                                    currentLine = word;
                                } else {
                                    currentLine = testLine;
                                }
                                
                                if (lines.length >= maxLines) break;
                            }
                            if (currentLine && lines.length < maxLines) {
                                lines.push(currentLine);
                            }
                            
                            // Draw the text lines
                            let textY = sectionY + 30 + fontSize;
                            lines.forEach((line, lineIndex) => {
                                if (textY + lineHeight <= sectionY + sectionHeight - 20) {
                                    ctx.fillText(line, sectionX + 30, textY);
                                    textY += lineHeight;
                                }
                            });
                        } else {
                            // No content generated, show placeholder
                            ctx.fillStyle = '#9ca3af';
                            ctx.font = 'italic 14px sans-serif';
                            ctx.textAlign = 'center';
                            ctx.fillText('No content generated', sectionX + sectionWidth / 2, sectionY + sectionHeight / 2);
                        }
                    });
                });
                
                // Save to localStorage
                saveAIContentToLocalStorage(generatedSections);
            }
            
            // Save AI generated content to localStorage
            function saveAIContentToLocalStorage(generatedSections) {
                const storageKey = `ai-generated-content-${documentId}`;
                try {
                    localStorage.setItem(storageKey, JSON.stringify(generatedSections));
                } catch (e) {
                    console.error('Failed to save AI content to localStorage:', e);
                }
            }
            
            // Save template structure to localStorage
            function saveTemplateToLocalStorage(sectionsData, pageWidth, pageHeight) {
                const storageKey = `ai-template-${documentId}`;
                try {
                    localStorage.setItem(storageKey, JSON.stringify({
                        sections: sectionsData,
                        pageWidth: pageWidth,
                        pageHeight: pageHeight,
                        timestamp: Date.now()
                    }));
                } catch (e) {
                    console.error('Failed to save template to localStorage:', e);
                }
            }
            
            // Save sections to database
            async function saveSectionsToDatabase(sectionsData, pageWidth, pageHeight) {
                try {
                    const response = await fetch('/ai/sections', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                        },
                        body: JSON.stringify({
                            document_id: documentId,
                            sections: sectionsData,
                            page_width: pageWidth,
                            page_height: pageHeight
                        })
                    });
                    
                    const data = await response.json();
                    if (data.success) {
                        console.log('Sections saved to database:', data.section_id);
                    } else {
                        console.error('Failed to save sections:', data.message);
                    }
                } catch (e) {
                    console.error('Error saving sections to database:', e);
                }
            }
            
            // Delete sections from database
            async function deleteSectionsFromDatabase() {
                try {
                    const response = await fetch(`/ai/sections/${documentId}`, {
                        method: 'DELETE',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                        }
                    });
                    
                    const data = await response.json();
                    if (data.success) {
                        console.log('Sections deleted from database');
                    } else {
                        console.error('Failed to delete sections:', data.message);
                    }
                } catch (e) {
                    console.error('Error deleting sections from database:', e);
                }
            }
            
            // Load sections from database and recreate template
            async function loadSectionsFromDatabase() {
                try {
                    const response = await fetch(`/ai/sections/${documentId}`, {
                        headers: {
                            'Accept': 'application/json',
                        }
                    });
                    
                    const data = await response.json();
                    if (data.success && data.sections) {
                        console.log('Loaded sections from database:', data.sections.length);
                        console.log('Generated content:', data.generated_content);
                        console.log('Generated images:', data.generated_images);
                        
                        // Recreate the template with saved sections and content
                        await recreateTemplateFromSections(
                            data.sections, 
                            data.page_width, 
                            data.page_height,
                            data.generated_content,
                            data.generated_images
                        );
                    }
                } catch (e) {
                    console.error('Error loading sections from database:', e);
                }
            }
            
            // Recreate template page from saved sections
            async function recreateTemplateFromSections(sectionsData, pageWidth, pageHeight, generatedContent, generatedImages) {
                // Get PDF dimensions if not provided
                if (!pageWidth || !pageHeight) {
                    if (!pdfjsDocument) {
                        await loadPdf();
                    }
                    const firstPage = await pdfjsDocument.getPage(1);
                    const viewport = firstPage.getViewport({ scale: currentScale });
                    pageWidth = viewport.width;
                    pageHeight = viewport.height;
                }
                
                // Create canvas
                const canvas = document.createElement('canvas');
                canvas.width = pageWidth;
                canvas.height = pageHeight;
                const ctx = canvas.getContext('2d');
                
                // White background
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, pageWidth, pageHeight);
                
                // Draw sections with content if available
                let currentY = 0;
                sectionsData.forEach((section, i) => {
                    const sectionHeight = pageHeight * (section.height / 100);
                    const sectionWidth = pageWidth * ((section.defaultWidth || 100) / 100);
                    const sectionX = (pageWidth - sectionWidth) / 2;
                    
                    const colors = ['#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981'];
                    ctx.strokeStyle = colors[i % colors.length];
                    ctx.lineWidth = 2;
                    ctx.strokeRect(sectionX + 10, currentY + 10, sectionWidth - 20, sectionHeight - 20);
                    
                    // Check if there's generated content for this section
                    const contentSection = generatedContent?.sections?.[i];
                    const imageData = generatedImages?.find(img => img.section_number === i);
                    const isImageSection = ['image', 'graphic', 'chart'].includes(section.type?.toLowerCase());
                    
                    if (isImageSection && imageData && imageData.image_data && imageData.storage_type === 'base64') {
                        // Render image asynchronously
                        const img = new Image();
                        img.onload = function() {
                            const padding = 20;
                            const maxWidth = sectionWidth - padding * 2;
                            const maxHeight = sectionHeight - padding * 2;
                            
                            let drawWidth = img.width;
                            let drawHeight = img.height;
                            const aspectRatio = drawWidth / drawHeight;
                            
                            if (drawWidth > maxWidth) {
                                drawWidth = maxWidth;
                                drawHeight = drawWidth / aspectRatio;
                            }
                            if (drawHeight > maxHeight) {
                                drawHeight = maxHeight;
                                drawWidth = drawHeight * aspectRatio;
                            }
                            
                            const imgX = sectionX + (sectionWidth - drawWidth) / 2;
                            const imgY = currentY + (sectionHeight - drawHeight) / 2;
                            
                            ctx.drawImage(img, imgX, imgY, drawWidth, drawHeight);
                        };
                        img.src = `data:${imageData.mime_type || 'image/png'};base64,${imageData.image_data}`;
                    } else if (contentSection && contentSection.content) {
                        // Render text content
                        ctx.fillStyle = '#1f2937';
                        ctx.font = '16px sans-serif';
                        ctx.textAlign = 'left';
                        
                        const content = contentSection.content;
                        const maxWidth = sectionWidth - 60;
                        const words = content.split(' ');
                        const lines = [];
                        let currentLine = '';
                        
                        words.forEach(word => {
                            const testLine = currentLine + (currentLine ? ' ' : '') + word;
                            const metrics = ctx.measureText(testLine);
                            if (metrics.width > maxWidth && currentLine) {
                                lines.push(currentLine);
                                currentLine = word;
                            } else {
                                currentLine = testLine;
                            }
                        });
                        if (currentLine) lines.push(currentLine);
                        
                        const lineHeight = 22;
                        const fontSize = 16;
                        let textY = currentY + 30 + fontSize;
                        lines.forEach((line) => {
                            if (textY + lineHeight <= currentY + sectionHeight - 20) {
                                ctx.fillText(line, sectionX + 30, textY);
                                textY += lineHeight;
                            }
                        });
                    } else {
                        // No content - show section info
                        ctx.fillStyle = '#000000';
                        ctx.font = 'bold 24px sans-serif';
                        ctx.textAlign = 'left';
                        ctx.fillText(section.icon || '', sectionX + 30, currentY + 50);
                        
                        ctx.font = 'bold 18px sans-serif';
                        ctx.fillText(section.name || section.type, sectionX + 70, currentY + 50);
                        
                        ctx.font = '14px sans-serif';
                        ctx.fillStyle = '#000000';
                        ctx.fillText(`H: ${section.height}% W: ${section.defaultWidth || 100}%`, sectionX + 30, currentY + 80);
                    }
                    
                    currentY += sectionHeight;
                });
                
                // Add to AI viewer
                const aiViewer = document.getElementById('ai-viewer');
                const wrapper = document.createElement('div');
                wrapper.className = 'page generated-page';
                wrapper.style.border = '3px solid #10b981';
                wrapper.style.position = 'relative';
                wrapper.appendChild(canvas);
                
                wrapper.sectionsData = sectionsData;
                wrapper.pageWidth = pageWidth;
                wrapper.pageHeight = pageHeight;
                wrapper.generatedContent = generatedContent;
                wrapper.generatedImages = generatedImages || [];
                
                // Create section overlays (same function as in template generation)
                sectionsData.forEach((section, i) => {
                    createSectionOverlayForRecreated(wrapper, section, i, sectionsData, pageWidth, pageHeight);
                });
                
                // Add page menu
                const menu = document.createElement('div');
                menu.className = 'generated-page-menu';
                menu.innerHTML = `
                    <button class="lock-sections-btn" title="Lock/Unlock sections">🔓</button>
                    <button class="add-section-btn" title="Add section">➕</button>
                    <button class="delete-page-btn" title="Delete page">🗑️</button>
                `;
                wrapper.appendChild(menu);
                
                const lockBtn = menu.querySelector('.lock-sections-btn');
                const addBtn = menu.querySelector('.add-section-btn');
                const deleteBtn = menu.querySelector('.delete-page-btn');
                
                let sectionsLocked = false;
                lockBtn.addEventListener('click', () => {
                    sectionsLocked = !sectionsLocked;
                    lockBtn.textContent = sectionsLocked ? '🔒' : '🔓';
                    const overlays = wrapper.querySelectorAll('.section-overlay');
                    overlays.forEach(overlay => {
                        overlay.style.pointerEvents = sectionsLocked ? 'none' : 'auto';
                    });
                });
                
                deleteBtn.addEventListener('click', () => {
                    if (confirm('Delete this generated page?')) {
                        wrapper.remove();
                        // Delete from database
                        deleteSectionsFromDatabase();
                        setStatus('Page deleted', 'ok');
                    }
                });
                
                // Add section handler
                addBtn.addEventListener('click', () => {
                    // Create and show modal
                    const addSectionModal = document.createElement('div');
                    addSectionModal.className = 'modal';
                    addSectionModal.style.display = 'flex';
                    addSectionModal.innerHTML = `
                        <div class="modal-content" style="max-width: 400px;">
                            <div class="modal-header">
                                <span>Add Section</span>
                                <button class="modal-close" type="button">×</button>
                            </div>
                            <div class="modal-body" style="padding: 20px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 12px;">Section Type</label>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                    <button class="section-type-btn" data-type="paragraph" style="padding: 20px; background: rgba(59, 130, 246, 0.1); border: 2px solid rgba(59, 130, 246, 0.3); border-radius: 8px; cursor: pointer; transition: all 0.2s;">
                                        <div style="font-size: 32px; margin-bottom: 8px;">📄</div>
                                        <div style="font-weight: 600;">Paragraph</div>
                                    </button>
                                    <button class="section-type-btn" data-type="title" style="padding: 20px; background: rgba(139, 92, 246, 0.1); border: 2px solid rgba(139, 92, 246, 0.3); border-radius: 8px; cursor: pointer; transition: all 0.2s;">
                                        <div style="font-size: 32px; margin-bottom: 8px;">📌</div>
                                        <div style="font-weight: 600;">Title</div>
                                    </button>
                                    <button class="section-type-btn" data-type="chart" style="padding: 20px; background: rgba(236, 72, 153, 0.1); border: 2px solid rgba(236, 72, 153, 0.3); border-radius: 8px; cursor: pointer; transition: all 0.2s;">
                                        <div style="font-size: 32px; margin-bottom: 8px;">📊</div>
                                        <div style="font-weight: 600;">Chart</div>
                                    </button>
                                    <button class="section-type-btn" data-type="graphic" style="padding: 20px; background: rgba(245, 158, 11, 0.1); border: 2px solid rgba(245, 158, 11, 0.3); border-radius: 8px; cursor: pointer; transition: all 0.2s;">
                                        <div style="font-size: 32px; margin-bottom: 8px;">🖼️</div>
                                        <div style="font-weight: 600;">Image</div>
                                    </button>
                                </div>
                            </div>
                        </div>
                    `;
                    document.body.appendChild(addSectionModal);
                    
                    // Add hover effects
                    const sectionTypeBtns = addSectionModal.querySelectorAll('.section-type-btn');
                    sectionTypeBtns.forEach(btn => {
                        btn.addEventListener('mouseenter', () => {
                            btn.style.transform = 'translateY(-2px)';
                            btn.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
                        });
                        btn.addEventListener('mouseleave', () => {
                            btn.style.transform = '';
                            btn.style.boxShadow = '';
                        });
                        btn.addEventListener('click', () => {
                            const sectionType = btn.dataset.type;
                            
                            // Add new section to the page
                            const newSection = {
                                type: sectionType,
                                height: 20,
                                defaultX: 10,
                                defaultY: sectionsData.length * 20,
                                defaultWidth: 80,
                                name: sectionType.charAt(0).toUpperCase() + sectionType.slice(1)
                            };
                            
                            sectionsData.push(newSection);
                            wrapper.sectionsData = sectionsData;
                            
                            // Recreate overlays
                            const allOverlays = wrapper.querySelectorAll('.section-overlay');
                            allOverlays.forEach(o => o.remove());
                            sectionsData.forEach((s, idx) => createSectionOverlayForRecreated(wrapper, s, idx, sectionsData, pageWidth, pageHeight));
                            
                            // Regenerate canvas
                            regenerateCanvas(wrapper);
                            
                            // Save to database
                            saveSectionsToDatabase(sectionsData, pageWidth, pageHeight);
                            
                            addSectionModal.remove();
                            setStatus('Section added', 'ok');
                        });
                    });
                    
                    // Close modal
                    const closeBtn = addSectionModal.querySelector('.modal-close');
                    closeBtn.addEventListener('click', () => addSectionModal.remove());
                    addSectionModal.addEventListener('click', (e) => {
                        if (e.target === addSectionModal) addSectionModal.remove();
                    });
                });
                
                aiViewer.insertBefore(wrapper, aiViewer.firstChild);
                console.log('Template recreated from database');
            }
            
            // Create section overlay for recreated template (helper function)
            function createSectionOverlayForRecreated(wrapper, section, i, sectionsToRender, pageWidth, pageHeight) {
                const sectionHeight = pageHeight * (section.height / 100);
                const sectionWidth = pageWidth * ((section.defaultWidth || 100) / 100);
                const sectionX = pageWidth * ((section.defaultX || 0) / 100);
                const sectionY = pageHeight * ((section.defaultY || 0) / 100);
                
                const sectionOverlay = document.createElement('div');
                sectionOverlay.className = 'section-overlay';
                sectionOverlay.style.cssText = `position: absolute; left: ${sectionX}px; top: ${sectionY}px; width: ${sectionWidth}px; height: ${sectionHeight}px; cursor: move; border: 2px solid transparent; transition: border-color 0.2s;`;
                sectionOverlay.dataset.sectionIndex = i;
                
                // Add controls panel
                const controlsPanel = document.createElement('div');
                controlsPanel.className = 'section-overlay-controls';
                controlsPanel.style.cssText = 'position: absolute; top: 5px; right: 5px; background: rgba(17, 24, 39, 0.95); border: 1px solid rgba(255,255,255,0.2); border-radius: 6px; padding: 8px; display: none; gap: 6px; align-items: center; z-index: 10; pointer-events: auto;';
                
                controlsPanel.innerHTML = `
                    <button class="delete-section-btn" style="background: rgba(239, 68, 68, 0.9); border: none; color: white; border-radius: 4px; padding: 4px 8px; cursor: pointer; font-size: 11px; font-weight: 600; transition: all 0.2s;" title="Delete section">🗑️</button>
                    <span style="color: #9ca3af; font-size: 11px; font-weight: 600;">X:</span>
                    <input type="number" class="overlay-x-input" value="${section.defaultX || 0}" min="-100" max="200" style="width: 50px; padding: 3px 5px; border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; background: rgba(255,255,255,0.1); color: white; font-size: 11px;">
                    <span style="color: #9ca3af; font-size: 11px; font-weight: 600;">Y:</span>
                    <input type="number" class="overlay-y-input" value="${section.defaultY || 0}" min="-100" max="200" style="width: 50px; padding: 3px 5px; border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; background: rgba(255,255,255,0.1); color: white; font-size: 11px;">
                    <span style="color: #9ca3af; font-size: 11px;">%</span>
                `;
                
                sectionOverlay.appendChild(controlsPanel);
                
                // Add resize handles
                const resizeHandles = ['n', 's', 'e', 'w', 'ne', 'nw', 'se', 'sw'];
                resizeHandles.forEach(dir => {
                    const handle = document.createElement('div');
                    handle.className = `resize-handle ${dir} ${['ne', 'nw', 'se', 'sw'].includes(dir) ? 'corner' : 'edge'}`;
                    handle.dataset.direction = dir;
                    sectionOverlay.appendChild(handle);
                });
                
                // Resize functionality
                let isResizing = false;
                let resizeDir = null;
                let resizeStartX, resizeStartY, resizeStartWidth, resizeStartHeight, resizeStartLeft, resizeStartTop;
                
                sectionOverlay.querySelectorAll('.resize-handle').forEach(handle => {
                    handle.addEventListener('mousedown', (e) => {
                        e.stopPropagation();
                        isResizing = true;
                        resizeDir = handle.dataset.direction;
                        resizeStartX = e.clientX;
                        resizeStartY = e.clientY;
                        resizeStartWidth = parseFloat(sectionOverlay.style.width);
                        resizeStartHeight = parseFloat(sectionOverlay.style.height);
                        resizeStartLeft = parseFloat(sectionOverlay.style.left);
                        resizeStartTop = parseFloat(sectionOverlay.style.top);
                        sectionOverlay.style.zIndex = '1000';
                        e.preventDefault();
                    });
                });
                
                document.addEventListener('mousemove', (e) => {
                    if (!isResizing) return;
                    const dx = e.clientX - resizeStartX;
                    const dy = e.clientY - resizeStartY;
                    
                    let newWidth = resizeStartWidth;
                    let newHeight = resizeStartHeight;
                    let newLeft = resizeStartLeft;
                    let newTop = resizeStartTop;
                    
                    // Handle resize based on direction
                    if (resizeDir.includes('e')) {
                        newWidth = Math.max(50, Math.min(resizeStartWidth + dx, pageWidth - resizeStartLeft));
                    }
                    if (resizeDir.includes('w')) {
                        const maxDx = resizeStartLeft;
                        const constrainedDx = Math.min(dx, maxDx);
                        newWidth = Math.max(50, resizeStartWidth - constrainedDx);
                        newLeft = resizeStartLeft + (resizeStartWidth - newWidth);
                    }
                    if (resizeDir.includes('s')) {
                        newHeight = Math.max(30, Math.min(resizeStartHeight + dy, pageHeight - resizeStartTop));
                    }
                    if (resizeDir.includes('n')) {
                        const maxDy = resizeStartTop;
                        const constrainedDy = Math.min(dy, maxDy);
                        newHeight = Math.max(30, resizeStartHeight - constrainedDy);
                        newTop = resizeStartTop + (resizeStartHeight - newHeight);
                    }
                    
                    sectionOverlay.style.width = newWidth + 'px';
                    sectionOverlay.style.height = newHeight + 'px';
                    sectionOverlay.style.left = newLeft + 'px';
                    sectionOverlay.style.top = newTop + 'px';
                });
                
                document.addEventListener('mouseup', () => {
                    if (isResizing) {
                        isResizing = false;
                        sectionOverlay.style.zIndex = '';
                        
                        // Update section data
                        const newWidth = parseFloat(sectionOverlay.style.width);
                        const newHeight = parseFloat(sectionOverlay.style.height);
                        const newLeft = parseFloat(sectionOverlay.style.left);
                        const newTop = parseFloat(sectionOverlay.style.top);
                        
                        sectionsToRender[i].defaultWidth = Math.round((newWidth / pageWidth) * 100);
                        sectionsToRender[i].height = Math.round((newHeight / pageHeight) * 100);
                        sectionsToRender[i].defaultX = Math.round((newLeft / pageWidth) * 100);
                        sectionsToRender[i].defaultY = Math.round((newTop / pageHeight) * 100);
                        
                        // Update input fields
                        xInput.value = sectionsToRender[i].defaultX;
                        yInput.value = sectionsToRender[i].defaultY;
                        
                        regenerateCanvas(wrapper);
                        saveSectionsToDatabase(sectionsToRender, pageWidth, pageHeight);
                    }
                });
                
                // Show/hide controls on hover
                sectionOverlay.addEventListener('mouseenter', () => {
                    controlsPanel.style.display = 'flex';
                    sectionOverlay.style.borderColor = '#3b82f6';
                });
                sectionOverlay.addEventListener('mouseleave', () => {
                    controlsPanel.style.display = 'none';
                    sectionOverlay.style.borderColor = 'transparent';
                });
                
                // Delete button
                const deleteBtn = controlsPanel.querySelector('.delete-section-btn');
                deleteBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (confirm('Delete this section?')) {
                        sectionsToRender.splice(i, 1);
                        wrapper.sectionsData = sectionsToRender;
                        sectionOverlay.remove();
                        regenerateCanvas(wrapper);
                        const allOverlays = wrapper.querySelectorAll('.section-overlay');
                        allOverlays.forEach(o => o.remove());
                        sectionsToRender.forEach((s, idx) => createSectionOverlayForRecreated(wrapper, s, idx, sectionsToRender, pageWidth, pageHeight));
                        saveSectionsToDatabase(sectionsToRender, pageWidth, pageHeight);
                    }
                });
                
                // X/Y input handlers
                const xInput = controlsPanel.querySelector('.overlay-x-input');
                const yInput = controlsPanel.querySelector('.overlay-y-input');
                
                xInput.addEventListener('input', (e) => {
                    const newX = parseInt(e.target.value) || 0;
                    const newLeft = (newX / 100) * pageWidth;
                    sectionOverlay.style.left = newLeft + 'px';
                    sectionsToRender[i].defaultX = newX;
                    regenerateCanvas(wrapper);
                    saveSectionsToDatabase(sectionsToRender, pageWidth, pageHeight);
                });
                
                yInput.addEventListener('input', (e) => {
                    const newY = parseInt(e.target.value) || 0;
                    const newTop = (newY / 100) * pageHeight;
                    sectionOverlay.style.top = newTop + 'px';
                    sectionsToRender[i].defaultY = newY;
                    regenerateCanvas(wrapper);
                    saveSectionsToDatabase(sectionsToRender, pageWidth, pageHeight);
                });
                
                // Drag functionality
                let isDragging = false;
                let startX, startY, offsetX, offsetY;
                
                sectionOverlay.addEventListener('mousedown', (e) => {
                    isDragging = true;
                    startX = e.clientX;
                    startY = e.clientY;
                    offsetX = parseFloat(sectionOverlay.style.left) || 0;
                    offsetY = parseFloat(sectionOverlay.style.top) || 0;
                    sectionOverlay.style.zIndex = '1000';
                    sectionOverlay.style.borderColor = '#3b82f6';
                    e.preventDefault();
                });
                
                document.addEventListener('mousemove', (e) => {
                    if (!isDragging) return;
                    const dx = e.clientX - startX;
                    const dy = e.clientY - startY;
                    let newLeft = offsetX + dx;
                    let newTop = offsetY + dy;
                    
                    // Constrain to page boundaries
                    const sectionWidth = parseFloat(sectionOverlay.style.width);
                    const sectionHeight = parseFloat(sectionOverlay.style.height);
                    newLeft = Math.max(0, Math.min(newLeft, pageWidth - sectionWidth));
                    newTop = Math.max(0, Math.min(newTop, pageHeight - sectionHeight));
                    
                    sectionOverlay.style.left = newLeft + 'px';
                    sectionOverlay.style.top = newTop + 'px';
                    const newX = Math.round((newLeft / pageWidth) * 100);
                    const newY = Math.round((newTop / pageHeight) * 100);
                    xInput.value = newX;
                    yInput.value = newY;
                });
                
                document.addEventListener('mouseup', () => {
                    if (isDragging) {
                        isDragging = false;
                        sectionOverlay.style.zIndex = '';
                        sectionOverlay.style.borderColor = 'transparent';
                        const newX = (parseFloat(sectionOverlay.style.left) / pageWidth) * 100;
                        const newY = (parseFloat(sectionOverlay.style.top) / pageHeight) * 100;
                        sectionsToRender[i].defaultX = Math.round(newX);
                        sectionsToRender[i].defaultY = Math.round(newY);
                        regenerateCanvas(wrapper);
                        saveSectionsToDatabase(sectionsToRender, pageWidth, pageHeight);
                    }
                });
                
                wrapper.appendChild(sectionOverlay);
            }
            
            // Load AI generated content from localStorage
            function loadAIContentFromLocalStorage() {
                const storageKey = `ai-generated-content-${documentId}`;
                try {
                    const stored = localStorage.getItem(storageKey);
                    if (stored) {
                        const generatedSections = JSON.parse(stored);
                        
                        // Apply content to any existing generated pages
                        const aiViewer = document.getElementById('ai-viewer');
                        const generatedPages = aiViewer ? aiViewer.querySelectorAll('.generated-page') : [];
                        
                        if (generatedPages.length > 0) {
                            renderContentIntoSections(generatedSections, []);
                        }
                    }
                } catch (e) {
                    console.error('Failed to load AI content from localStorage:', e);
                }
            }
            
            // Send message
            function sendChatMessage(confirmed = false, costPayload = null) {
                if (!confirmed && (!aiChatInput || !aiChatInput.value.trim())) return;
                
                const userMessage = confirmed && costPayload ? costPayload.prompt : (aiChatInput ? aiChatInput.value.trim() : '');
                
                if (!confirmed) {
                    addChatMessage(userMessage, true);
                    
                    // Clear input
                    aiChatInput.value = '';
                    aiChatInput.style.height = 'auto';
                }
                
                // Collect sections data from generated pages in AI viewer
                const aiViewer = document.getElementById('ai-viewer');
                const generatedPages = aiViewer ? aiViewer.querySelectorAll('.generated-page') : [];
                
                let allSections = [];
                let templateType = selectedLayout || 'custom';
                
                generatedPages.forEach((page, pageIndex) => {
                    if (page.sectionsData) {
                        const pageWidth = page.pageWidth;
                        const pageHeight = page.pageHeight;
                        
                        page.sectionsData.forEach((section, sectionIndex) => {
                            allSections.push({
                                type: section.type || 'text',
                                name: section.name || section.type || 'Section',
                                height: section.height || 0,
                                width: section.defaultWidth || 100,
                                x: section.defaultX || 0,
                                y: section.defaultY || 0,
                                order: pageIndex * 100 + sectionIndex,
                                page: pageIndex + 1,
                                dimensions: {
                                    pageWidth: pageWidth,
                                    pageHeight: pageHeight,
                                    sectionWidthPx: pageWidth * ((section.defaultWidth || 100) / 100),
                                    sectionHeightPx: pageHeight * (section.height / 100),
                                    sectionXPx: pageWidth * ((section.defaultX || 0) / 100),
                                    sectionYPx: pageHeight * ((section.defaultY || 0) / 100)
                                }
                            });
                        });
                    }
                });
                
                // Prepare payload with custom prompt settings
                const payload = costPayload || {
                    prompt: userMessage,
                    template: templateType,
                    document_id: documentId,
                    sections: allSections,
                    model: 'gpt-4',
                    prompt_settings: promptSettings, // Include custom prompt settings
                    confirmed: confirmed
                };
                
                // Send to backend
                fetch('/ai/chat', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify(payload)
                })
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(err => {
                            throw new Error(err.message || 'Request failed');
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    // Check if cost confirmation is required
                    if (data.requires_confirmation) {
                        showCostConfirmationModal(data.cost_estimate, payload);
                        return;
                    }
                    
                    if (data.success) {
                        // Use the parsed sections from the backend
                        const parsedSections = data.data.parsed_sections;
                        const generatedImages = data.data.generated_images || [];
                        
                        if (parsedSections && parsedSections.sections && Array.isArray(parsedSections.sections)) {
                            // Display formatted sections
                            const sectionsSummary = parsedSections.sections.map((s, i) => 
                                `\n${i + 1}. ${s.type}: ${s.content.substring(0, 100)}...`
                            ).join('');
                            
                            let message = `Generated content for ${parsedSections.sections.length} sections:${sectionsSummary}`;
                            if (generatedImages.length > 0) {
                                message += `\n\n🖼️ Generated ${generatedImages.length} images`;
                            }
                            addChatMessage(message, false);
                            
                            // Render the content into the generated page sections
                            renderContentIntoSections(parsedSections.sections, allSections, generatedImages);
                        } else if (parsedSections && parsedSections.raw_response) {
                            // Fallback to raw response if parsing failed
                            addChatMessage(parsedSections.raw_response, false);
                        } else {
                            // Fallback to generated content
                            addChatMessage(data.data.generated_content || 'Content generated successfully', false);
                        }
                    } else {
                        addChatMessage('Error processing your request', false);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    addChatMessage(`Error: ${error.message || 'Could not connect to AI service'}`, false);
                });
            }
            
            // Show cost confirmation modal
            function showCostConfirmationModal(costEstimate, originalPayload) {
                const modal = document.getElementById('cost-confirmation-modal');
                
                // Populate cost data
                document.getElementById('cost-total').textContent = costEstimate.total_cost_usd.toFixed(4);
                document.getElementById('cost-text').textContent = costEstimate.text_generation.cost_usd.toFixed(6);
                document.getElementById('cost-images').textContent = costEstimate.image_generation.cost_usd.toFixed(4);
                document.getElementById('cost-image-count').textContent = costEstimate.image_generation.count;
                document.getElementById('cost-input-tokens').textContent = costEstimate.text_generation.input_tokens.toLocaleString();
                document.getElementById('cost-output-tokens').textContent = costEstimate.text_generation.output_tokens.toLocaleString();
                document.getElementById('cost-total-tokens').textContent = costEstimate.text_generation.total_tokens.toLocaleString();
                
                // Show modal
                modal.style.display = 'flex';
                
                // Set up confirm button
                const confirmBtn = document.getElementById('cost-modal-confirm');
                const cancelBtn = document.getElementById('cost-modal-cancel');
                const closeBtn = document.getElementById('cost-modal-close');
                
                const confirmHandler = () => {
                    modal.style.display = 'none';
                    originalPayload.confirmed = true;
                    sendChatMessage(true, originalPayload);
                    cleanup();
                };
                
                const cancelHandler = () => {
                    modal.style.display = 'none';
                    addChatMessage('Request cancelled', false);
                    cleanup();
                };
                
                const cleanup = () => {
                    confirmBtn.removeEventListener('click', confirmHandler);
                    cancelBtn.removeEventListener('click', cancelHandler);
                    closeBtn.removeEventListener('click', cancelHandler);
                };
                
                confirmBtn.addEventListener('click', confirmHandler);
                cancelBtn.addEventListener('click', cancelHandler);
                closeBtn.addEventListener('click', cancelHandler);
            }
            
            // Event listeners
            if (aiSendBtn) {
                aiSendBtn.addEventListener('click', sendChatMessage);
            }
            
            if (aiChatInput) {
                aiChatInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        sendChatMessage();
                    }
                });
            }
            
            if (aiAttachBtn) {
                aiAttachBtn.addEventListener('click', () => {
                    // Placeholder for attach functionality
                    console.log('Attach button clicked');
                });
            }
            
            // Request History functionality
            const historyToggle = document.getElementById('history-toggle');
            const historyContent = document.getElementById('history-content');
            const historyHeader = document.getElementById('history-header');
            const historyList = document.getElementById('history-list');
            
            if (historyToggle && historyHeader) {
                historyHeader.addEventListener('click', () => {
                    const isOpen = historyContent.style.display === 'block';
                    historyContent.style.display = isOpen ? 'none' : 'block';
                    historyToggle.classList.toggle('open', !isOpen);
                    
                    // Load history when opening
                    if (!isOpen) {
                        loadRequestHistory();
                    }
                });
            }
            
            async function loadRequestHistory() {
                try {
                    const response = await fetch(`/ai/price-log?document_id=${documentId}`, {
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken
                        }
                    });
                    
                    const data = await response.json();
                    
                    if (data.success && data.logs) {
                        displayRequestHistory(data.logs);
                    }
                } catch (error) {
                    console.error('Error loading request history:', error);
                }
            }
            
            function displayRequestHistory(logs) {
                if (!historyList) return;
                
                if (logs.length === 0) {
                    historyList.innerHTML = '<div style="padding: 16px; text-align: center; color: #9ca3af; font-size: 13px;">No requests yet</div>';
                    return;
                }
                
                historyList.innerHTML = logs.map(log => {
                    const date = new Date(log.created_at);
                    const cost = parseFloat(log.cost_usd || log.estimated_cost_usd || 0);
                    
                    return `
                        <div class="history-item">
                            <div class="history-item-header">
                                <div class="history-item-cost">$${cost.toFixed(4)}</div>
                                <div class="history-item-date">${date.toLocaleDateString()} ${date.toLocaleTimeString()}</div>
                            </div>
                            <div class="history-item-prompt">${log.prompt_preview || 'No prompt'}</div>
                            <div class="history-item-stats">
                                <div class="history-item-stat">
                                    <span>📝</span>
                                    <span>${(log.total_tokens || 0).toLocaleString()} tokens</span>
                                </div>
                                ${log.image_count > 0 ? `
                                <div class="history-item-stat">
                                    <span>🖼️</span>
                                    <span>${log.image_count} images</span>
                                </div>
                                ` : ''}
                            </div>
                        </div>
                    `;
                }).join('');
            }
            
            // Add to PDF functionality
            const addToPdfBtn = document.getElementById('ai-add-to-pdf-btn');
            
            if (addToPdfBtn) {
                addToPdfBtn.addEventListener('click', async () => {
                    const aiViewer = document.getElementById('ai-viewer');
                    const generatedPages = aiViewer ? aiViewer.querySelectorAll('.generated-page') : [];
                    
                    if (generatedPages.length === 0) {
                        alert('No pages to add. Generate content first.');
                        return;
                    }
                    
                    // Collect canvas data from all generated pages
                    const images = [];
                    for (const page of generatedPages) {
                        const canvas = page.querySelector('canvas');
                        if (canvas) {
                            images.push(canvas.toDataURL('image/png').split(',')[1]); // Get base64 without prefix
                        }
                    }
                    
                    if (images.length === 0) {
                        alert('No content to add.');
                        return;
                    }
                    
                    try {
                        addToPdfBtn.disabled = true;
                        addToPdfBtn.textContent = 'Adding...';
                        
                        const response = await fetch('/ai/add-to-pdf', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken
                            },
                            body: JSON.stringify({
                                document_id: documentId,
                                images: images
                            })
                        });
                        
                        const data = await response.json();
                        
                        if (data.success) {
                            alert(`Successfully added ${data.pages_added} pages to PDF! Reloading document...`);
                            // Reload the page to show the updated PDF
                            window.location.reload();
                        } else {
                            alert('Error: ' + (data.message || 'Failed to add pages to PDF'));
                            console.error('Add to PDF error:', data);
                        }
                    } catch (error) {
                        console.error('Error adding to PDF:', error);
                        alert('Error adding pages to PDF: ' + error.message);
                    } finally {
                        addToPdfBtn.disabled = false;
                        addToPdfBtn.innerHTML = `
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 8px;">
                                <path d="M12 5v14M5 12h14"/>
                            </svg>
                            Add to PDF
                        `;
                    }
                });
            }
        </script>
        
        <!-- Bootstrap 5.3.3 JS Bundle -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    </body>
</html>
