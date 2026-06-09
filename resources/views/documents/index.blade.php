<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>PDF Editor</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            :root {
                --page-bg: #f5f6fa;
                --surface: rgba(255, 255, 255, 0.86);
                --surface-strong: #ffffff;
                --surface-muted: #eef1f6;
                --surface-soft: #f8f9fc;
                --border: #dfe4ee;
                --border-strong: #cfd7e6;
                --ink: #111827;
                --muted: #687387;
                --muted-soft: #8b95a7;
                --accent: #121c84;
                --accent-soft: #e8ebff;
                --accent-line: #cdd4ff;
                --danger: #c93f4d;
                --shadow-soft: 0 20px 60px rgba(15, 23, 42, 0.06);
                --shadow-card: 0 20px 40px rgba(15, 23, 42, 0.08);
                --radius-xl: 26px;
                --radius-lg: 20px;
                --radius-md: 14px;
                --radius-sm: 10px;
                --font-display: "Sora", "Avenir Next", "Segoe UI", sans-serif;
                --font-body: "IBM Plex Sans", "Segoe UI", sans-serif;
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;
                background:
                    radial-gradient(circle at top left, rgba(223, 228, 238, 0.75), transparent 32%),
                    linear-gradient(180deg, #fafbfe 0%, var(--page-bg) 100%);
                color: var(--ink);
                font-family: var(--font-body);
            }

            .uploader-page {
                padding: 28px 16px 56px;
            }

            .page-container {
                max-width: 1080px;
                margin: 0 auto;
            }

            .dashboard-shell {
                display: grid;
                gap: 28px;
            }

            .status-stack {
                display: grid;
                gap: 12px;
            }

            .status-banner {
                padding: 14px 18px;
                border-radius: var(--radius-md);
                border: 1px solid var(--border);
                background: rgba(255, 255, 255, 0.72);
                box-shadow: var(--shadow-soft);
                font-size: 14px;
                line-height: 1.45;
            }

            .status-banner.error {
                color: #8d2430;
                border-color: #f2c7ce;
                background: #fff5f6;
            }

            .status-banner.success {
                color: #1d4f3a;
                border-color: #bfdfcf;
                background: #f3fbf7;
            }

            .upload-hero {
                background: var(--surface);
                border: 1px solid rgba(255, 255, 255, 0.9);
                border-radius: var(--radius-xl);
                padding: 30px;
                box-shadow: var(--shadow-soft);
                backdrop-filter: blur(18px);
            }

            .upload {
                display: grid;
                gap: 18px;
            }

            .upload-input {
                display: none;
            }

            .upload-dropzone {
                min-height: 170px;
                border: 1.5px dashed var(--border);
                border-radius: 16px;
                background:
                    linear-gradient(180deg, rgba(255, 255, 255, 0.84) 0%, rgba(247, 249, 253, 0.95) 100%);
                display: grid;
                place-items: center;
                text-align: center;
                padding: 28px 24px;
                cursor: pointer;
                transition: border-color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
                outline: none;
            }

            .upload-dropzone:hover,
            .upload-dropzone:focus,
            .upload-dropzone.dragover {
                border-color: var(--accent-line);
                box-shadow: 0 16px 32px rgba(18, 28, 132, 0.08);
                transform: translateY(-1px);
                background: linear-gradient(180deg, rgba(255, 255, 255, 0.96) 0%, rgba(238, 241, 248, 0.98) 100%);
            }

            .upload-hero-content {
                display: grid;
                justify-items: center;
                gap: 10px;
            }

            .upload-hero-icon {
                width: 52px;
                height: 52px;
                display: grid;
                place-items: center;
                border-radius: 50%;
                background: #e8ebf4;
                color: var(--accent);
            }

            .upload-hero strong {
                display: block;
                font-size: 18px;
                line-height: 1.3;
                font-weight: 700;
                letter-spacing: -0.02em;
                color: var(--ink);
            }

            .upload-hero span {
                color: var(--muted);
                font-size: 14px;
            }

            .upload-meta {
                display: flex;
                gap: 12px;
                align-items: center;
                flex-wrap: wrap;
            }

            .upload-file-name {
                flex: 1;
                min-width: 220px;
                padding: 14px 16px;
                border-radius: 14px;
                border: 1px solid var(--border);
                background: rgba(255, 255, 255, 0.72);
                color: var(--muted);
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8);
                font-size: 14px;
            }

            .button-primary,
            .button-secondary,
            .button-danger,
            .doc-link,
            .template-link {
                appearance: none;
                border: none;
                cursor: pointer;
                font: inherit;
                text-decoration: none;
                transition: transform 0.16s ease, box-shadow 0.16s ease, opacity 0.16s ease, background 0.16s ease;
            }

            .button-primary {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                padding: 14px 22px;
                border-radius: 12px;
                background: var(--accent);
                color: #ffffff;
                font-weight: 700;
                box-shadow: 0 14px 28px rgba(18, 28, 132, 0.18);
            }

            .button-primary:hover,
            .button-secondary:hover,
            .button-danger:hover,
            .doc-link:hover,
            .template-link:hover {
                transform: translateY(-1px);
            }

            .button-secondary {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                padding: 11px 15px;
                border-radius: 12px;
                background: rgba(255, 255, 255, 0.78);
                border: 1px solid var(--border);
                color: var(--ink);
                font-weight: 600;
            }

            .button-danger {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                padding: 11px 15px;
                border-radius: 12px;
                background: #fff4f5;
                border: 1px solid #f2c7ce;
                color: var(--danger);
                font-weight: 700;
            }

            .upload-error {
                display: none;
                color: var(--danger);
                font-size: 13px;
                font-weight: 700;
            }

            .upload-progress {
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .upload-progress-track {
                height: 10px;
                flex: 1;
                min-width: 180px;
                border-radius: 999px;
                background: #e8edf6;
                overflow: hidden;
            }

            .upload-progress-bar {
                width: 0%;
                height: 100%;
                background: linear-gradient(90deg, #1a2ac3 0%, #4b5cff 100%);
                transition: width 0.12s linear;
            }

            .upload-progress-value {
                min-width: 42px;
                font-size: 12px;
                color: var(--muted);
                text-align: right;
                font-variant-numeric: tabular-nums;
            }

            .workspace-grid {
                display: grid;
                grid-template-columns: minmax(0, 1fr);
                gap: 24px;
                align-items: start;
            }

            .section-card {
                background: var(--surface);
                border-radius: var(--radius-lg);
                border: 1px solid rgba(255, 255, 255, 0.9);
                box-shadow: var(--shadow-card);
                backdrop-filter: blur(16px);
            }

            .blank-card {
                padding: 22px;
                display: grid;
                grid-template-columns: minmax(180px, 260px) minmax(0, 1fr);
                gap: 22px;
                align-items: end;
            }

            .blank-card-header {
                display: grid;
                gap: 12px;
            }

            .eyebrow {
                font-size: 12px;
                line-height: 1;
                font-weight: 800;
                letter-spacing: 0.18em;
                text-transform: uppercase;
                color: var(--accent);
                font-family: var(--font-display);
            }

            .blank-card h2,
            .template-pane h2,
            .docs-section h2 {
                margin: 0;
                font-family: var(--font-display);
                font-size: 18px;
                line-height: 1.25;
                letter-spacing: -0.03em;
            }

            .blank-controls {
                display: grid;
                grid-template-columns: minmax(180px, 1fr) minmax(240px, 1fr) auto;
                align-items: end;
                gap: 16px;
            }

            .field-group {
                display: grid;
                gap: 8px;
            }

            .field-label {
                font-size: 12px;
                font-weight: 800;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                color: #4e5970;
            }

            .field-select {
                width: 100%;
                border: 1px solid var(--border);
                border-radius: 10px;
                background: rgba(255, 255, 255, 0.9);
                padding: 12px 14px;
                color: var(--ink);
                font: inherit;
            }

            .segmented {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }

            .segmented input {
                position: absolute;
                opacity: 0;
                pointer-events: none;
            }

            .segmented label {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                padding: 10px 12px;
                border-radius: 10px;
                border: 1px solid var(--border);
                background: rgba(255, 255, 255, 0.64);
                color: var(--muted);
                font-size: 13px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.16s ease;
            }

            .segmented input:checked + label {
                border-color: var(--accent-line);
                background: #ffffff;
                color: var(--accent);
                box-shadow: inset 0 0 0 1px rgba(18, 28, 132, 0.06);
            }

            .blank-card .button-primary {
                width: 100%;
                margin-top: auto;
                min-height: 44px;
            }

            .template-pane {
                padding: 0;
                overflow: hidden;
            }

            .template-pane-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-end;
                gap: 16px;
                padding: 4px 4px 18px;
            }

            .template-pane-copy {
                display: grid;
                gap: 10px;
            }

            .template-link {
                color: var(--accent);
                font-size: 13px;
                font-weight: 700;
                white-space: nowrap;
            }

            .template-gallery {
                display: grid;
                gap: 24px;
            }

            .template-category-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 16px;
            }

            .template-category-card {
                appearance: none;
                display: grid;
                grid-template-columns: auto minmax(0, 1fr) auto;
                align-items: center;
                gap: 16px;
                width: 100%;
                min-height: 116px;
                padding: 18px;
                border: 1px solid var(--border);
                border-radius: 14px;
                background: #ffffff;
                color: #0f172a;
                cursor: pointer;
                text-align: left;
                box-shadow: 0 14px 32px rgba(15, 23, 42, 0.08);
                transition: transform 0.16s ease, border-color 0.16s ease, box-shadow 0.16s ease;
            }

            .template-category-card:hover {
                transform: translateY(-2px);
                border-color: rgba(18, 28, 132, 0.28);
                box-shadow: 0 20px 42px rgba(15, 23, 42, 0.12);
            }

            .template-category-icon {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 52px;
                height: 52px;
                border-radius: 14px;
                background: rgba(18, 28, 132, 0.08);
                color: var(--accent);
            }

            .template-category-icon svg {
                width: 26px;
                height: 26px;
            }

            .template-category-copy {
                display: grid;
                gap: 6px;
                min-width: 0;
            }

            .template-category-title {
                font-size: 21px;
                font-family: var(--font-display);
                font-weight: 800;
                letter-spacing: -0.01em;
            }

            .template-category-subtitle,
            .template-group-subtitle {
                color: var(--muted);
                font-size: 13px;
                line-height: 1.45;
            }

            .template-category-count {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-width: 34px;
                height: 34px;
                padding: 0 10px;
                border-radius: 999px;
                background: #f1f5f9;
                color: #334155;
                font-size: 13px;
                font-weight: 800;
            }

            .template-detail-pane[hidden],
            .template-category-grid[hidden],
            .template-group[hidden] {
                display: none !important;
            }

            .template-group {
                display: grid;
                gap: 18px;
            }

            .template-group-header {
                display: flex;
                align-items: center;
                gap: 14px;
            }

            .template-group-back {
                appearance: none;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 38px;
                height: 38px;
                border: 1px solid var(--border);
                border-radius: 999px;
                background: #ffffff;
                color: #0f172a;
                cursor: pointer;
                box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
                transition: transform 0.16s ease, border-color 0.16s ease;
            }

            .template-group-back:hover {
                transform: translateY(-1px);
                border-color: rgba(18, 28, 132, 0.28);
            }

            .template-group-title {
                margin: 0;
                color: #0f172a;
                font-size: 22px;
                font-family: var(--font-display);
                font-weight: 800;
                letter-spacing: -0.01em;
            }

            .template-group-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 16px;
            }

            .template-form {
                margin: 0;
            }

            .template-card {
                display: grid;
                gap: 12px;
                width: 100%;
                padding: 0;
                background: transparent;
                text-align: left;
                color: inherit;
            }

            .template-preview {
                position: relative;
                min-height: 248px;
                border-radius: 16px;
                overflow: hidden;
                border: 1px solid var(--border);
                background: linear-gradient(145deg, #fdfdfe 0%, #eef1f5 100%);
                box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9);
            }

            .template-preview::after {
                content: "";
                position: absolute;
                inset: 0;
                background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(15,23,42,0.02));
                pointer-events: none;
            }

            .template-preview > * {
                width: 100%;
                height: 100%;
            }

            .template-card:hover .template-preview {
                transform: translateY(-2px);
                box-shadow: 0 18px 36px rgba(15, 23, 42, 0.12);
            }

            .template-meta {
                display: grid;
                gap: 4px;
                padding: 0 2px;
            }

            .template-title {
                font-size: 18px;
                font-family: var(--font-display);
                font-weight: 700;
                letter-spacing: -0.02em;
            }

            .template-subtitle {
                font-size: 12px;
                color: var(--muted);
                line-height: 1.45;
            }

            .docs-section {
                display: grid;
                gap: 18px;
            }

            .docs-section-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 14px;
            }

            .docs-section-actions {
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
            }

            .select-all-wrap {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 8px 12px;
                border-radius: 999px;
                background: rgba(255, 255, 255, 0.78);
                border: 1px solid var(--border);
                color: var(--muted);
                font-size: 12px;
                font-weight: 700;
            }

            .docs-grid {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 18px;
            }

            .doc-card {
                position: relative;
                background: rgba(255, 255, 255, 0.84);
                border-radius: 18px;
                border: 1px solid rgba(255, 255, 255, 0.92);
                overflow: hidden;
                box-shadow: var(--shadow-card);
                display: grid;
                min-height: 270px;
            }

            .doc-card-select {
                position: absolute;
                top: 14px;
                left: 14px;
                z-index: 3;
                width: 16px;
                height: 16px;
                accent-color: var(--accent);
                cursor: pointer;
            }

            .doc-preview {
                min-height: 154px;
                background:
                    linear-gradient(180deg, rgba(244, 246, 251, 0.8) 0%, rgba(237, 241, 247, 0.92) 100%);
                display: grid;
                place-items: center;
                padding: 30px 24px 22px;
                overflow: hidden;
            }

            .doc-preview.has-image {
                padding: 16px;
                align-items: stretch;
            }

            .doc-preview-frame {
                width: 100%;
                height: 100%;
                display: grid;
                place-items: center;
                border-radius: 14px;
                background: linear-gradient(180deg, rgba(255, 255, 255, 0.96) 0%, rgba(245, 247, 252, 0.96) 100%);
                box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9);
                overflow: hidden;
            }

            .doc-preview-image {
                display: block;
                width: auto;
                max-width: 100%;
                height: 100%;
                max-height: 120px;
                object-fit: contain;
                border-radius: 4px;
                box-shadow: 0 18px 28px rgba(15, 23, 42, 0.12);
                background: #ffffff;
            }

            .doc-paper {
                width: 66px;
                height: 86px;
                border-radius: 3px;
                background: #ffffff;
                box-shadow: 0 18px 28px rgba(15, 23, 42, 0.12);
                position: relative;
            }

            .doc-paper::before,
            .doc-paper::after {
                content: "";
                position: absolute;
                left: 10px;
                right: 10px;
                border-radius: 999px;
                background: #edf2f8;
            }

            .doc-paper::before {
                top: 14px;
                height: 5px;
            }

            .doc-paper::after {
                top: 26px;
                height: 34px;
            }

            .doc-paper-grid {
                position: absolute;
                left: 10px;
                right: 10px;
                bottom: 12px;
                height: 22px;
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 3px;
            }

            .doc-paper-grid span {
                background: #f1f4f9;
                border-radius: 2px;
            }

            .doc-paper.ai-mode::before {
                background: #e7ddff;
            }

            .doc-paper.ai-mode::after {
                background: linear-gradient(180deg, #f6f1ff 0%, #ece4ff 100%);
            }

            .doc-paper.guided-mode::before {
                background: #d9e8ff;
            }

            .doc-paper.guided-mode::after {
                background: linear-gradient(180deg, #eef5ff 0%, #dbe9ff 100%);
            }

            .doc-body {
                display: grid;
                gap: 12px;
                padding: 14px 14px 16px;
                align-content: start;
            }

            .doc-title {
                font-size: 14px;
                line-height: 1.35;
                font-weight: 700;
                color: var(--ink);
                word-break: break-word;
            }

            .doc-meta {
                font-size: 10px;
                line-height: 1.45;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                color: var(--muted-soft);
            }

            .doc-actions {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
            }
            .doc-actions > * {
                flex: 1 1 0;
                min-width: 0;
            }

            .doc-link {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 10px 12px;
                border-radius: 10px;
                background: var(--accent-soft);
                color: var(--accent);
                font-size: 12px;
                font-weight: 700;
            }

            .doc-link-outline {
                background: transparent;
                border: 1px solid var(--accent);
                opacity: 0.78;
            }

            .doc-link-outline:hover {
                opacity: 1;
                background: var(--accent-soft);
            }

            .doc-empty {
                padding: 28px;
                border: 1px dashed var(--border-strong);
                border-radius: var(--radius-lg);
                background: rgba(255, 255, 255, 0.56);
                color: var(--muted);
                text-align: center;
            }

            .mode-pill {
                position: absolute;
                top: 14px;
                right: 14px;
                z-index: 3;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 5px 8px;
                border-radius: 999px;
                font-size: 10px;
                font-weight: 800;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                color: var(--accent);
                background: rgba(255, 255, 255, 0.92);
                border: 1px solid rgba(18, 28, 132, 0.12);
            }

            .limit-modal {
                position: fixed;
                inset: 0;
                z-index: 100000;
                background: rgba(15, 23, 42, 0.48);
                display: none;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            .limit-modal-card {
                width: min(460px, 94vw);
                padding: 24px;
                border-radius: 18px;
                background: #ffffff;
                border: 1px solid var(--border);
                box-shadow: 0 34px 90px rgba(15, 23, 42, 0.18);
            }

            .limit-modal-title {
                margin: 0 0 10px;
                font-family: var(--font-display);
                font-size: 24px;
                letter-spacing: -0.03em;
            }

            .limit-modal-copy {
                margin: 0 0 18px;
                color: var(--muted);
                line-height: 1.55;
            }

            .limit-modal-actions {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
            }

            .limit-modal-actions a {
                text-decoration: none;
            }

            @media (max-width: 1024px) {
                .docs-grid,
                .template-group-grid,
                .template-category-grid {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }

                .blank-card,
                .blank-controls {
                    grid-template-columns: minmax(0, 1fr);
                }
            }

            @media (max-width: 768px) {
                .uploader-page {
                    padding: 22px 14px 44px;
                }

                .upload-hero,
                .blank-card,
                .template-pane {
                    padding: 20px;
                }

                .docs-grid,
                .template-group-grid,
                .template-category-grid {
                    grid-template-columns: 1fr;
                }

                .docs-section-header,
                .template-pane-header {
                    align-items: flex-start;
                    flex-direction: column;
                }

                .upload-meta {
                    flex-direction: column;
                    align-items: stretch;
                }

                .button-primary,
                .button-secondary,
                .button-danger {
                    width: 100%;
                }

                .doc-actions {
                    flex-direction: column;
                }
            }
        </style>
    </head>
    <body class="min-h-screen antialiased">
        <x-site-header />

        <main class="uploader-page">
            <div class="page-container">
                <div class="dashboard-shell">
                    <div class="status-stack">
                        @if (session('status'))
                            <div class="status-banner success">{{ session('status') }}</div>
                        @endif

                        @if ($errors->any())
                            <div class="status-banner error">{{ $errors->first() }}</div>
                        @endif
                    </div>

                    <section class="upload-hero">
                        <form class="upload" id="upload-form" action="{{ route('documents.store') }}" method="POST" enctype="multipart/form-data">
                            @csrf
                            <input id="document-mode-input" type="hidden" name="document_mode" value="editor">
                            <input id="document-input" class="upload-input" type="file" name="document" accept="application/pdf,.pdf" required>

                            <div id="upload-dropzone" class="upload-dropzone" role="button" tabindex="0" aria-label="Upload PDF by click or drag and drop">
                                <div class="upload-hero-content">
                                    <div class="upload-hero-icon" aria-hidden="true">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7z"></path>
                                            <path d="M14 2v5h5"></path>
                                            <path d="M12 11v6"></path>
                                            <path d="M9 14h6"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <strong>Drag and drop your PDF here</strong>
                                        <span>or select a file from your computer</span>
                                    </div>
                                </div>
                            </div>

                            <div class="upload-meta">
                                <div id="upload-file-name" class="upload-file-name">No file selected</div>
                                <button id="upload-submit" class="button-primary" type="submit">Upload PDF</button>
                            </div>

                            <div id="upload-error" class="upload-error"></div>

                            <div id="upload-progress" class="upload-progress" style="display:none;" aria-live="polite">
                                <div class="upload-progress-track">
                                    <div id="upload-progress-bar" class="upload-progress-bar"></div>
                                </div>
                                <div id="upload-progress-value" class="upload-progress-value">0%</div>
                            </div>
                        </form>
                    </section>

                    <section class="workspace-grid">
                        <div class="section-card blank-card">
                            <div class="blank-card-header">
                                <div class="eyebrow">New Project</div>
                                <h2>Start with Empty PDF</h2>
                            </div>

                            <form action="{{ route('documents.createBlank') }}" method="POST" class="blank-controls">
                                @csrf
                                <div class="field-group">
                                    <label class="field-label" for="blank-page-size">Page Size</label>
                                    <select id="blank-page-size" class="field-select" name="page_size">
                                        <option value="Letter" selected>Letter (8.5 × 11 in)</option>
                                        <option value="A4">A4 (210 × 297 mm)</option>
                                        <option value="Legal">Legal (8.5 × 14 in)</option>
                                        <option value="A3">A3 (297 × 420 mm)</option>
                                        <option value="A5">A5 (148 × 210 mm)</option>
                                    </select>
                                </div>

                                <div class="field-group">
                                    <span class="field-label">Orientation</span>
                                    <div class="segmented">
                                        <div>
                                            <input id="orientation-portrait" type="radio" name="orientation" value="portrait" checked>
                                            <label for="orientation-portrait">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <rect x="7" y="3" width="10" height="18" rx="1.8"></rect>
                                                </svg>
                                                Portrait
                                            </label>
                                        </div>
                                        <div>
                                            <input id="orientation-landscape" type="radio" name="orientation" value="landscape">
                                            <label for="orientation-landscape">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <rect x="3" y="7" width="18" height="10" rx="1.8"></rect>
                                                </svg>
                                                Landscape
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" class="button-primary">Create Blank PDF</button>
                            </form>
                        </div>

                        <div class="section-card template-pane" id="templates-gallery">
                            <div style="padding: 22px;">
                                <div class="template-pane-header">
                                    <div class="template-pane-copy">
                                        <div class="eyebrow">Accelerate Workflow</div>
                                        <h2>Guided Templates</h2>
                                    </div>
                                </div>

                                @php
                                    $workflowGroups = [
                                        'realestate' => [
                                            'label' => 'Real Estate',
                                            'summary' => 'Lease extensions and deposit workflows',
                                        ],
                                        'invoice' => [
                                            'label' => 'Invoice',
                                            'summary' => 'Clean invoice layouts and billing forms',
                                        ],
                                    ];
                                    $workflowTemplates = collect($guidedTemplates)
                                        ->whereIn('type', array_keys($workflowGroups))
                                        ->groupBy('type');
                                @endphp
                                <div class="template-gallery" data-template-workflow>
                                    <div class="template-category-grid" id="template-category-grid">
                                        @foreach ($workflowGroups as $type => $group)
                                            @php $templatesForGroup = ($workflowTemplates->get($type) ?? collect())->sortBy('sort_order'); @endphp
                                            @if ($templatesForGroup->isNotEmpty())
                                                <button
                                                    type="button"
                                                    class="template-category-card"
                                                    data-template-category-open="{{ $type }}"
                                                    aria-controls="template-panel-{{ $type }}"
                                                    aria-expanded="false"
                                                >
                                                    <span class="template-category-icon" aria-hidden="true">
                                                        @if ($type === 'realestate')
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                                <path d="M3 11.5 12 4l9 7.5"></path>
                                                                <path d="M5 10.5V20h14v-9.5"></path>
                                                                <path d="M9 20v-6h6v6"></path>
                                                            </svg>
                                                        @else
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                                <path d="M7 3h10v18l-2-1.4-2 1.4-2-1.4-2 1.4-2-1.4V3Z"></path>
                                                                <path d="M10 8h6"></path>
                                                                <path d="M10 12h6"></path>
                                                                <path d="M10 16h3"></path>
                                                            </svg>
                                                        @endif
                                                    </span>
                                                    <span class="template-category-copy">
                                                        <span class="template-category-title">{{ $group['label'] }}</span>
                                                        <span class="template-category-subtitle">{{ $group['summary'] }}</span>
                                                    </span>
                                                    <span class="template-category-count">{{ $templatesForGroup->count() }}</span>
                                                </button>
                                            @endif
                                        @endforeach
                                    </div>

                                    <div class="template-detail-pane" id="template-detail-pane" hidden>
                                    @foreach ($workflowGroups as $type => $group)
                                        @php $templatesForGroup = ($workflowTemplates->get($type) ?? collect())->sortBy('sort_order'); @endphp
                                        @if ($templatesForGroup->isNotEmpty())
                                            <section
                                                class="template-group"
                                                id="template-panel-{{ $type }}"
                                                data-template-category-panel="{{ $type }}"
                                                aria-labelledby="template-group-{{ $type }}"
                                                hidden
                                            >
                                                <div class="template-group-header">
                                                    <button type="button" class="template-group-back" data-template-category-back aria-label="Back to template categories">
                                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                            <path d="m15 18-6-6 6-6"></path>
                                                        </svg>
                                                    </button>
                                                    <div>
                                                        <h3 class="template-group-title" id="template-group-{{ $type }}">{{ $group['label'] }}</h3>
                                                        <div class="template-group-subtitle">{{ $group['summary'] }}</div>
                                                    </div>
                                                </div>
                                                <div class="template-group-grid">
                                                    @foreach ($templatesForGroup as $tpl)
                                                        <form class="template-form" action="{{ $tpl->type === 'invoice' ? route('documents.createSimpleInvoice') : route('documents.createFromGuidedTemplate') }}" method="POST">
                                                            @csrf
                                                            @php $defaults = $tpl->defaults ?? []; @endphp
                                                            @if ($tpl->type === 'invoice')
                                                                <input type="hidden" name="company_name" value="{{ $defaults['company_name'] ?? 'Your Company Inc.' }}">
                                                                <input type="hidden" name="company_address" value="{{ $defaults['company_address'] ?? '' }}">
                                                                <input type="hidden" name="customer_name" value="{{ $defaults['customer_name'] ?? 'Customer Name' }}">
                                                                <input type="hidden" name="customer_address" value="{{ $defaults['customer_address'] ?? '' }}">
                                                                <input type="hidden" name="invoice_number" value="{{ $defaults['invoice_number'] ?? '0001001' }}">
                                                                <input type="hidden" name="invoice_date" value="{{ date('m-d-Y') }}">
                                                                <input type="hidden" name="due_date" value="{{ date('m-d-Y', strtotime('+14 days')) }}">
                                                                <input type="hidden" name="terms" value="{{ $defaults['terms'] ?? '' }}">
                                                                <input type="hidden" name="_guided" value="1">
                                                                @if ($tpl->slug !== 'default')
                                                                    <input type="hidden" name="style" value="{{ $tpl->slug }}">
                                                                @endif
                                                            @else
                                                                <input type="hidden" name="_template_type" value="{{ $tpl->type }}">
                                                                <input type="hidden" name="_template_slug" value="{{ $tpl->slug }}">
                                                                <input type="hidden" name="_guided" value="1">
                                                            @endif

                                                            <button type="submit" class="template-card">
                                                                <div class="template-preview">
                                                                    {!! $tpl->preview_html !!}
                                                                </div>
                                                                <div class="template-meta">
                                                                    <div class="template-title">{{ $tpl->name }}</div>
                                                                    <div class="template-subtitle">{{ $tpl->description }}</div>
                                                                </div>
                                                            </button>
                                                        </form>
                                                    @endforeach
                                                </div>
                                            </section>
                                        @endif
                                    @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="docs-section">
                        <div class="docs-section-header">
                            <h2>Recent Documents</h2>
                            <div class="docs-section-actions">
                                @if ($documents->count() > 0)
                                    <label class="select-all-wrap" for="select-all-checkbox">
                                        <input type="checkbox" id="select-all-checkbox" onchange="toggleSelectAll(this)" style="accent-color: var(--accent);">
                                        <span>Select All</span>
                                    </label>
                                    <button id="bulk-delete-btn" class="button-danger" style="display:none;" onclick="submitBulkDelete()">
                                        Delete Selected (<span id="selected-count">0</span>)
                                    </button>
                                @endif
                            </div>
                        </div>

                        <form id="bulk-delete-form" action="{{ route('documents.bulkDestroy') }}" method="POST" style="display:none;">
                            @csrf
                        </form>

                        <div class="docs-grid">
                            @forelse ($documents as $document)
                                @php
                                    $editUrl = $document->mode === 'guided'
                                        ? route('documents.guided', $document)
                                        : ($document->mode === 'ai'
                                            ? route('documents.ai', $document)
                                            : ($document->mode === 'full_editor'
                                                ? route('documents.editPdfjs', $document)
                                                : route('documents.edit', $document)));
                                    $openNewUrl = $document->mode === 'guided'
                                        ? route('documents.guided', $document)
                                        : route('documents.editNew', ['document' => $document, 'pdfjs' => 1]);
                                    $sizeMb = $document->size_bytes > 0 ? number_format($document->size_bytes / (1024 * 1024), 1) : '0.0';
                                    $updatedLabel = optional($document->updated_at)->diffForHumans() ?: 'just now';
                                    $paperClass = $document->mode === 'ai' ? 'ai-mode' : ($document->mode === 'guided' ? 'guided-mode' : '');
                                    $previewDataUrl = (!empty($document->preview_image) && !empty($document->preview_image_mime_type))
                                        ? ('data:' . $document->preview_image_mime_type . ';base64,' . $document->preview_image)
                                        : null;
                                @endphp
                                <div class="doc-card">
                                    <input type="checkbox" class="doc-card-select doc-checkbox" value="{{ $document->id }}" onchange="updateBulkState()">
                                    @if($document->mode === 'guided')
                                        <div class="mode-pill">Guided</div>
                                    @elseif($document->mode === 'ai')
                                        <div class="mode-pill">AI</div>
                                    @elseif($document->mode === 'full_editor')
                                        <div class="mode-pill">Full Editor</div>
                                    @endif
                                    <div class="doc-preview{{ $previewDataUrl ? ' has-image' : '' }}">
                                        @if ($previewDataUrl)
                                            <div class="doc-preview-frame">
                                                <img
                                                    class="doc-preview-image"
                                                    src="{{ $previewDataUrl }}"
                                                    alt="Preview of {{ $document->original_name }}"
                                                    loading="lazy"
                                                >
                                            </div>
                                        @else
                                            <div class="doc-paper {{ $paperClass }}">
                                                <div class="doc-paper-grid">
                                                    <span></span>
                                                    <span></span>
                                                    <span></span>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="doc-body">
                                        <div class="doc-title">{{ $document->original_name }}</div>
                                        <div class="doc-meta">Edited {{ strtoupper($updatedLabel) }} &bull; {{ $sizeMb }} MB</div>
                                        <div class="doc-actions">
                                            <a href="{{ $editUrl }}" class="doc-link">Open</a>
                                            @if(strtolower((string) config('pdf_editor.layout_mode', 'default')) === 'new_writer')
                                            <a href="{{ $openNewUrl }}" target="_blank" class="doc-link doc-link-outline">Open New</a>
                                            @endif
                                            <form action="{{ route('documents.destroy', $document) }}" method="POST" style="margin:0;">
                                                @csrf
                                                @method('DELETE')
                                                <button class="button-secondary" type="submit" style="width:100%;" onclick="return confirm('Delete this document?')">Delete</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="doc-empty">
                                    Upload a PDF or create a blank document to start your recent documents list.
                                </div>
                            @endforelse
                        </div>
                    </section>
                </div>
            </div>
        </main>

        <div id="pdf-upload-limit-modal" class="limit-modal" aria-hidden="true">
            <div class="limit-modal-card">
                <h3 class="limit-modal-title">Out of PDF uploads</h3>
                <p class="limit-modal-copy">You are out of PDF uploads for this month. Please look at the subscription plans to continue.</p>
                <div class="limit-modal-actions">
                    <a href="/portal/subscription"><button type="button" class="button-primary">View Subscription Plans</button></a>
                    <button id="pdf-upload-limit-close" type="button" class="button-secondary">Close</button>
                </div>
            </div>
        </div>

        <script>
            function shouldShowUploadLimitModal(message) {
                if (!message) return false;
                const text = String(message).toLowerCase();
                return text.includes('monthly pdf upload limit reached') || text.includes('out of pdf uploads');
            }

            function showUploadLimitModal() {
                const modal = document.getElementById('pdf-upload-limit-modal');
                if (!modal) return;
                modal.style.display = 'flex';
                modal.setAttribute('aria-hidden', 'false');
            }

            function hideUploadLimitModal() {
                const modal = document.getElementById('pdf-upload-limit-modal');
                if (!modal) return;
                modal.style.display = 'none';
                modal.setAttribute('aria-hidden', 'true');
            }

            function updateBulkState() {
                const checkboxes = document.querySelectorAll('.doc-checkbox:checked');
                const allCheckboxes = document.querySelectorAll('.doc-checkbox');
                const btn = document.getElementById('bulk-delete-btn');
                const countSpan = document.getElementById('selected-count');
                const selectAll = document.getElementById('select-all-checkbox');

                if (btn) {
                    btn.style.display = checkboxes.length > 0 ? 'inline-flex' : 'none';
                }
                if (countSpan) {
                    countSpan.textContent = checkboxes.length;
                }

                if (selectAll) {
                    selectAll.checked = checkboxes.length > 0 && checkboxes.length === allCheckboxes.length;
                    selectAll.indeterminate = checkboxes.length > 0 && checkboxes.length < allCheckboxes.length;
                }
            }

            function toggleSelectAll(selectAllCheckbox) {
                const checkboxes = document.querySelectorAll('.doc-checkbox');
                checkboxes.forEach((checkbox) => {
                    checkbox.checked = selectAllCheckbox.checked;
                });
                updateBulkState();
            }

            function submitBulkDelete() {
                if (!confirm('Are you sure you want to delete the selected documents?')) return;

                const form = document.getElementById('bulk-delete-form');
                const checkboxes = document.querySelectorAll('.doc-checkbox:checked');

                form.innerHTML = '@csrf';

                checkboxes.forEach((checkbox) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'ids[]';
                    input.value = checkbox.value;
                    form.appendChild(input);
                });

                form.submit();
            }

            function initUploadDropzone() {
                const form = document.getElementById('upload-form');
                const input = document.getElementById('document-input');
                const dropzone = document.getElementById('upload-dropzone');
                const fileName = document.getElementById('upload-file-name');
                const error = document.getElementById('upload-error');
                const progress = document.getElementById('upload-progress');
                const progressBar = document.getElementById('upload-progress-bar');
                const progressValue = document.getElementById('upload-progress-value');
                const submitBtn = document.getElementById('upload-submit');

                if (!form || !input || !dropzone || !fileName || !error || !progress || !progressBar || !progressValue || !submitBtn) {
                    return;
                }

                let selectedFile = null;

                const isPdf = (file) => {
                    if (!file) return false;
                    const mime = (file.type || '').toLowerCase();
                    const name = (file.name || '').toLowerCase();
                    return mime === 'application/pdf' || name.endsWith('.pdf');
                };

                const formatBytes = (bytes) => {
                    if (!bytes || bytes < 1024) return `${bytes || 0} B`;
                    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
                    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
                };

                const showError = (message) => {
                    error.textContent = message;
                    error.style.display = 'block';
                };

                const clearError = () => {
                    error.textContent = '';
                    error.style.display = 'none';
                };

                const setProgress = (percent) => {
                    const value = Math.max(0, Math.min(100, Math.round(percent)));
                    progressBar.style.width = `${value}%`;
                    progressValue.textContent = `${value}%`;
                };

                const setFile = (file) => {
                    if (!file) return;
                    if (!isPdf(file)) {
                        selectedFile = null;
                        input.value = '';
                        fileName.textContent = 'No file selected';
                        showError('Only PDF files are allowed.');
                        return;
                    }

                    clearError();
                    selectedFile = file;
                    fileName.textContent = `${file.name} (${formatBytes(file.size)})`;

                    try {
                        const transfer = new DataTransfer();
                        transfer.items.add(file);
                        input.files = transfer.files;
                    } catch (_) {}
                };

                dropzone.addEventListener('click', () => input.click());
                dropzone.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        input.click();
                    }
                });

                input.addEventListener('change', () => setFile(input.files[0]));

                ['dragenter', 'dragover'].forEach((eventName) => {
                    dropzone.addEventListener(eventName, (event) => {
                        event.preventDefault();
                        dropzone.classList.add('dragover');
                    });
                });

                ['dragleave', 'drop'].forEach((eventName) => {
                    dropzone.addEventListener(eventName, (event) => {
                        event.preventDefault();
                        dropzone.classList.remove('dragover');
                    });
                });

                dropzone.addEventListener('drop', (event) => {
                    const file = event.dataTransfer?.files?.[0];
                    setFile(file);
                });

                const sendUpload = (file, extraFields) => {
                    const data = new FormData(form);
                    data.set('document', file);
                    if (extraFields && typeof extraFields === 'object') {
                        Object.keys(extraFields).forEach((key) => {
                            data.set(key, extraFields[key]);
                        });
                    }

                    submitBtn.disabled = true;
                    progress.style.display = 'flex';
                    setProgress(0);

                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', form.action, true);
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                    xhr.upload.addEventListener('progress', (uploadEvent) => {
                        if (!uploadEvent.lengthComputable) return;
                        setProgress((uploadEvent.loaded / uploadEvent.total) * 100);
                    });

                    xhr.addEventListener('load', () => {
                        if (xhr.status >= 200 && xhr.status < 300) {
                            setProgress(100);
                            window.location.href = xhr.responseURL || window.location.href;
                            return;
                        }

                        let body = null;
                        try {
                            body = JSON.parse(xhr.responseText);
                        } catch (_) {}

                        if (xhr.status === 409 && body && body.duplicate_name) {
                            submitBtn.disabled = false;
                            progress.style.display = 'none';
                            handleDuplicateName(body, file);
                            return;
                        }

                        submitBtn.disabled = false;
                        progress.style.display = 'none';
                        let message = 'Upload failed. Please try again.';
                        if (body) {
                            message = body.errors?.document?.[0] || body.message || message;
                        }
                        if (shouldShowUploadLimitModal(message)) {
                            showUploadLimitModal();
                        }
                        showError(message);
                    });

                    xhr.addEventListener('error', () => {
                        submitBtn.disabled = false;
                        progress.style.display = 'none';
                        showError('Network error while uploading. Please try again.');
                    });

                    xhr.send(data);
                };

                const handleDuplicateName = (body, file) => {
                    const existingName = body.existing_name || 'this name';
                    const openExisting = window.confirm(
                        'A document named "' + existingName + '" already exists.\n\n' +
                        'Click OK to open the existing document, or Cancel to rename and upload this file as a new document.'
                    );

                    if (openExisting) {
                        if (body.existing_url) {
                            window.location.href = body.existing_url;
                        }
                        return;
                    }

                    const suggested = (file.name || 'document').replace(/\.pdf$/i, '') + ' (copy).pdf';
                    const newName = window.prompt('Enter a new name for this document:', suggested);
                    if (newName === null) {
                        return;
                    }

                    const trimmed = newName.trim();
                    if (trimmed === '') {
                        showError('Document name cannot be empty.');
                        return;
                    }

                    clearError();
                    sendUpload(file, { rename_to: trimmed });
                };

                form.addEventListener('submit', (event) => {
                    event.preventDefault();
                    clearError();

                    const file = selectedFile || input.files[0];
                    if (!file) {
                        showError('Please choose a PDF file before uploading.');
                        return;
                    }
                    if (!isPdf(file)) {
                        showError('Only PDF files are allowed.');
                        return;
                    }

                    sendUpload(file, {});
                });
            }

            function initTemplateWorkflow() {
                const categoryGrid = document.getElementById('template-category-grid');
                const detailPane = document.getElementById('template-detail-pane');
                const openButtons = Array.from(document.querySelectorAll('[data-template-category-open]'));
                const panels = Array.from(document.querySelectorAll('[data-template-category-panel]'));
                const backButtons = Array.from(document.querySelectorAll('[data-template-category-back]'));

                if (!categoryGrid || !detailPane || openButtons.length === 0 || panels.length === 0) {
                    return;
                }

                const showCategories = () => {
                    categoryGrid.hidden = false;
                    detailPane.hidden = true;
                    panels.forEach((panel) => {
                        panel.hidden = true;
                    });
                    openButtons.forEach((button) => {
                        button.setAttribute('aria-expanded', 'false');
                    });
                };

                const openCategory = (category) => {
                    const panel = panels.find((item) => item.dataset.templateCategoryPanel === category);
                    if (!panel) return;

                    categoryGrid.hidden = true;
                    detailPane.hidden = false;
                    panels.forEach((item) => {
                        item.hidden = item !== panel;
                    });
                    openButtons.forEach((button) => {
                        button.setAttribute('aria-expanded', button.dataset.templateCategoryOpen === category ? 'true' : 'false');
                    });
                };

                openButtons.forEach((button) => {
                    button.addEventListener('click', () => openCategory(button.dataset.templateCategoryOpen));
                });
                backButtons.forEach((button) => {
                    button.addEventListener('click', showCategories);
                });
            }

            document.addEventListener('DOMContentLoaded', () => {
                updateBulkState();
                initUploadDropzone();
                initTemplateWorkflow();

                const uploadModeInput = document.getElementById('document-mode-input');
                if (uploadModeInput) {
                    const params = new URLSearchParams(window.location.search);
                    const regressionUpload = params.get('regression') === '1' || navigator.webdriver === true;
                    uploadModeInput.value = regressionUpload ? 'regression' : 'editor';
                }

                const closeBtn = document.getElementById('pdf-upload-limit-close');
                const modal = document.getElementById('pdf-upload-limit-modal');
                if (closeBtn) {
                    closeBtn.addEventListener('click', hideUploadLimitModal);
                }
                if (modal) {
                    modal.addEventListener('click', (event) => {
                        if (event.target === modal) {
                            hideUploadLimitModal();
                        }
                    });
                }

                const serverError = @json($errors->first());
                if (shouldShowUploadLimitModal(serverError)) {
                    showUploadLimitModal();
                }
            });
        </script>
    </body>
</html>
