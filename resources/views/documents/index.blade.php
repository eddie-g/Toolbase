<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PDF Editor - Netkit</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/netkit_logo_cube.svg') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root {
            --nk-bg: #ffffff;
            --nk-surface: #ffffff;
            --nk-surface-2: #fafafa;
            --nk-surface-3: #f4f4f5;
            --nk-border: #e4e4e7;
            --nk-border-2: #d4d4d8;
            --nk-ink: #18181b;
            --nk-ink-2: #3f3f46;
            --nk-muted: #71717a;
            --nk-muted-2: #a1a1aa;
            --nk-accent: #2563eb;
            --nk-accent-ink: #1d4ed8;
            --nk-accent-soft: #eff6ff;
            --nk-accent-line: #bfdbfe;
            --nk-danger: #dc2626;
            --nk-danger-soft: #fef2f2;
            --nk-danger-line: #fecaca;
            --nk-ok: #15803d;
            --nk-ok-soft: #f0fdf4;
            --nk-ok-line: #bbf7d0;
            --nk-primary-bg: #18181b;
            --nk-primary-ink: #ffffff;
            --nk-primary-hover: #27272a;
            --nk-shadow: 0 1px 2px rgba(24, 24, 27, 0.05);
            --nk-shadow-lg: 0 24px 60px -30px rgba(24, 24, 27, 0.35);
            --nk-radius: 12px;
            --nk-radius-sm: 8px;
        }
        .dark {
            --nk-bg: #09090b;
            --nk-surface: #09090b;
            --nk-surface-2: #18181b;
            --nk-surface-3: #27272a;
            --nk-border: #27272a;
            --nk-border-2: #3f3f46;
            --nk-ink: #fafafa;
            --nk-ink-2: #e4e4e7;
            --nk-muted: #a1a1aa;
            --nk-muted-2: #71717a;
            --nk-accent: #60a5fa;
            --nk-accent-ink: #93c5fd;
            --nk-accent-soft: #172554;
            --nk-accent-line: #1e40af;
            --nk-danger: #f87171;
            --nk-danger-soft: #2a1215;
            --nk-danger-line: #7f1d1d;
            --nk-ok: #4ade80;
            --nk-ok-soft: #052e16;
            --nk-ok-line: #166534;
            --nk-primary-bg: #ffffff;
            --nk-primary-ink: #18181b;
            --nk-primary-hover: #e4e4e7;
            --nk-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
            --nk-shadow-lg: 0 24px 70px -24px rgba(0, 0, 0, 0.8);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--nk-bg);
            color: var(--nk-ink);
            font-family: 'Inter', 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
            font-feature-settings: 'cv11', 'ss01';
            -webkit-font-smoothing: antialiased;
        }

        .uploader-page { padding: 120px 16px 72px; }
        .page-container { max-width: 1120px; margin: 0 auto; }
        .dashboard-shell { display: grid; gap: 28px; }

        .page-intro { display: grid; gap: 8px; max-width: 640px; }
        .eyebrow {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--nk-accent);
        }
        .page-intro h1 {
            margin: 0;
            font-size: clamp(28px, 3.6vw, 36px);
            font-weight: 600;
            letter-spacing: -0.025em;
            line-height: 1.15;
            text-wrap: balance;
        }
        .page-intro p { margin: 0; color: var(--nk-muted); font-size: 15px; line-height: 1.6; }

        .status-stack { display: grid; gap: 10px; }
        .status-stack:empty { display: none; }
        .docs-pagination { display: flex; align-items: center; justify-content: center; gap: 14px; margin-top: 22px; }
        .docs-pagination .button-secondary { text-decoration: none; }
        .docs-pagination .is-disabled { opacity: .45; pointer-events: none; }
        .docs-pagination-status { font-size: 13px; color: var(--nk-muted, #6b7280); }
        .status-banner {
            padding: 12px 14px;
            border-radius: var(--nk-radius-sm);
            border: 1px solid var(--nk-border);
            background: var(--nk-surface-2);
            font-size: 14px;
            line-height: 1.5;
        }
        .status-banner.error { color: var(--nk-danger); border-color: var(--nk-danger-line); background: var(--nk-danger-soft); }
        .status-banner.success { color: var(--nk-ok); border-color: var(--nk-ok-line); background: var(--nk-ok-soft); }

        .section-card, .upload-hero, .docs-section {
            background: var(--nk-surface);
            border: 1px solid var(--nk-border);
            border-radius: var(--nk-radius);
            box-shadow: var(--nk-shadow);
        }

        /* Upload */
        .upload-hero { padding: 20px; }
        .upload { display: grid; gap: 14px; }
        .upload-input { display: none; }
        .upload-dropzone {
            min-height: 168px;
            border: 1.5px dashed var(--nk-border-2);
            border-radius: var(--nk-radius);
            background:
                radial-gradient(circle at 1px 1px, color-mix(in srgb, var(--nk-ink) 7%, transparent) 1px, transparent 0) 0 0 / 18px 18px,
                var(--nk-surface-2);
            display: grid;
            place-items: center;
            text-align: center;
            padding: 28px 24px;
            cursor: pointer;
            outline: none;
            transition: border-color 0.16s ease, background-color 0.16s ease, box-shadow 0.16s ease;
        }
        .upload-dropzone:hover,
        .upload-dropzone:focus-visible,
        .upload-dropzone.dragover {
            border-color: var(--nk-accent);
            box-shadow: 0 0 0 4px color-mix(in srgb, var(--nk-accent) 14%, transparent);
        }
        .upload-hero-content { display: grid; justify-items: center; gap: 12px; }
        .upload-hero-icon {
            width: 44px;
            height: 44px;
            display: grid;
            place-items: center;
            border-radius: 10px;
            border: 1px solid var(--nk-border);
            background: var(--nk-surface);
            color: var(--nk-ink-2);
            box-shadow: var(--nk-shadow);
        }
        .upload-hero strong {
            display: block;
            font-size: 15px;
            font-weight: 600;
            letter-spacing: -0.01em;
            color: var(--nk-ink);
        }
        .upload-hero span { display: block; margin-top: 2px; color: var(--nk-muted); font-size: 13px; }
        .upload-meta { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .upload-file-name {
            flex: 1;
            min-width: 220px;
            padding: 9px 12px;
            border-radius: var(--nk-radius-sm);
            border: 1px solid var(--nk-border);
            background: var(--nk-surface);
            color: var(--nk-muted);
            font-size: 14px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .upload-error { display: none; color: var(--nk-danger); font-size: 13px; font-weight: 500; }
        .upload-progress { display: flex; align-items: center; gap: 10px; }
        .upload-progress-track { height: 6px; flex: 1; min-width: 180px; border-radius: 999px; background: var(--nk-surface-3); overflow: hidden; }
        .upload-progress-bar { width: 0%; height: 100%; background: var(--nk-accent); transition: width 0.12s linear; }
        .upload-progress-value { min-width: 42px; font-size: 12px; color: var(--nk-muted); text-align: right; font-variant-numeric: tabular-nums; }

        /* Buttons */
        .button-primary, .button-secondary, .button-danger, .doc-link, .template-link {
            appearance: none;
            border: 1px solid transparent;
            cursor: pointer;
            font: inherit;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 14px;
            border-radius: var(--nk-radius-sm);
            font-size: 14px;
            font-weight: 500;
            line-height: 1.2;
            white-space: nowrap;
            transition: background-color 0.16s ease, border-color 0.16s ease, color 0.16s ease, box-shadow 0.16s ease;
        }
        .button-primary { background: var(--nk-primary-bg); color: var(--nk-primary-ink); box-shadow: var(--nk-shadow); }
        .button-primary:hover { background: var(--nk-primary-hover); }
        .button-primary:disabled { opacity: 0.6; cursor: progress; }
        .button-secondary, .doc-link { background: var(--nk-surface); color: var(--nk-ink-2); border-color: var(--nk-border); }
        .button-secondary:hover, .doc-link:hover { border-color: var(--nk-border-2); background: var(--nk-surface-2); color: var(--nk-ink); }
        .doc-link { background: var(--nk-accent-soft); color: var(--nk-accent-ink); border-color: var(--nk-accent-line); }
        .doc-link:hover { background: color-mix(in srgb, var(--nk-accent-soft) 70%, var(--nk-accent-line)); color: var(--nk-accent-ink); }
        .button-danger { background: var(--nk-danger-soft); color: var(--nk-danger); border-color: var(--nk-danger-line); }
        .button-danger:hover { background: color-mix(in srgb, var(--nk-danger-soft) 70%, var(--nk-danger-line)); }
        .button-primary:focus-visible, .button-secondary:focus-visible, .button-danger:focus-visible, .doc-link:focus-visible {
            outline: 2px solid var(--nk-accent);
            outline-offset: 2px;
        }

        /* Workspace: blank + templates */
        .workspace-grid {
            display: grid;
            grid-template-columns: minmax(0, 5fr) minmax(0, 7fr);
            gap: 20px;
            align-items: start;
        }
        .section-card { padding: 20px; }
        .card-header { display: grid; gap: 4px; margin-bottom: 16px; }
        .card-header h2 { margin: 0; font-size: 17px; font-weight: 600; letter-spacing: -0.015em; }
        .card-header p { margin: 0; color: var(--nk-muted); font-size: 13px; line-height: 1.5; }

        .blank-controls { display: grid; gap: 14px; }
        .field-group { display: grid; gap: 6px; }
        .field-label { font-size: 12px; font-weight: 600; color: var(--nk-ink-2); letter-spacing: 0.02em; }
        .field-select {
            width: 100%;
            appearance: none;
            padding: 9px 36px 9px 12px;
            border-radius: var(--nk-radius-sm);
            border: 1px solid var(--nk-border);
            background:
                url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none' stroke='%2371717a' stroke-width='1.5'%3E%3Cpath d='M6 8l4 4 4-4'/%3E%3C/svg%3E") no-repeat right 10px center / 16px 16px,
                var(--nk-surface);
            color: var(--nk-ink);
            font: inherit;
            font-size: 14px;
        }
        .field-select:focus-visible { outline: 2px solid var(--nk-accent); outline-offset: 1px; }
        .segmented { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0; border: 1px solid var(--nk-border); border-radius: var(--nk-radius-sm); padding: 3px; background: var(--nk-surface-3); }
        .segmented > div { position: relative; }
        .segmented input { position: absolute; opacity: 0; pointer-events: none; }
        .segmented label {
            display: inline-flex;
            width: 100%;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 7px 10px;
            border-radius: 6px;
            color: var(--nk-muted);
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.16s ease, color 0.16s ease, box-shadow 0.16s ease;
        }
        .segmented input:checked + label { background: var(--nk-surface); color: var(--nk-ink); box-shadow: var(--nk-shadow); }
        .segmented input:focus-visible + label { outline: 2px solid var(--nk-accent); outline-offset: 1px; }

        .template-gallery { display: grid; gap: 12px; }
        .template-category-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .template-category-grid[hidden], .template-detail-pane[hidden], .template-group[hidden] { display: none; }
        .template-category-card {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            padding: 14px;
            border-radius: var(--nk-radius);
            border: 1px solid var(--nk-border);
            background: var(--nk-surface);
            color: inherit;
            font: inherit;
            text-align: left;
            cursor: pointer;
            transition: border-color 0.16s ease, background-color 0.16s ease;
        }
        .template-category-card:hover { border-color: var(--nk-border-2); background: var(--nk-surface-2); }
        .template-category-card:focus-visible { outline: 2px solid var(--nk-accent); outline-offset: 2px; }
        .template-category-icon {
            flex: none;
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            border-radius: 9px;
            border: 1px solid var(--nk-border);
            background: var(--nk-surface-2);
            color: var(--nk-ink-2);
        }
        .template-category-icon svg { width: 18px; height: 18px; }
        .template-category-copy { display: grid; gap: 2px; min-width: 0; flex: 1; }
        .template-category-title { font-size: 14px; font-weight: 600; letter-spacing: -0.01em; }
        .template-category-subtitle { font-size: 12.5px; color: var(--nk-muted); line-height: 1.4; }
        .template-category-count {
            flex: none;
            min-width: 24px;
            height: 24px;
            padding: 0 7px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            border: 1px solid var(--nk-border);
            background: var(--nk-surface-2);
            font-size: 12px;
            font-weight: 600;
            color: var(--nk-ink-2);
            font-variant-numeric: tabular-nums;
        }
        .template-group { display: grid; gap: 14px; }
        .template-group-header { display: flex; align-items: center; gap: 10px; }
        .template-group-back {
            width: 32px;
            height: 32px;
            display: grid;
            place-items: center;
            border-radius: var(--nk-radius-sm);
            border: 1px solid var(--nk-border);
            background: var(--nk-surface);
            color: var(--nk-ink-2);
            cursor: pointer;
        }
        .template-group-back:hover { background: var(--nk-surface-2); border-color: var(--nk-border-2); }
        .template-group-back svg { width: 16px; height: 16px; }
        .template-group-title { margin: 0; font-size: 15px; font-weight: 600; letter-spacing: -0.01em; }
        .template-group-subtitle { font-size: 12.5px; color: var(--nk-muted); }
        .template-group-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .template-form { margin: 0; }
        .template-card {
            display: grid;
            gap: 10px;
            width: 100%;
            padding: 0;
            border: none;
            background: transparent;
            text-align: left;
            color: inherit;
            font: inherit;
            cursor: pointer;
        }
        .template-preview {
            position: relative;
            aspect-ratio: 300 / 210;
            border-radius: var(--nk-radius-sm);
            overflow: hidden;
            border: 1px solid var(--nk-border);
            background: var(--nk-surface-2);
            transition: border-color 0.16s ease, box-shadow 0.16s ease;
        }
        .template-preview > * { display: block; width: 100%; height: 100%; }
        .template-card:hover .template-preview { border-color: var(--nk-accent); box-shadow: 0 0 0 4px color-mix(in srgb, var(--nk-accent) 14%, transparent); }
        .template-card:focus-visible { outline: none; }
        .template-card:focus-visible .template-preview { outline: 2px solid var(--nk-accent); outline-offset: 2px; }
        .template-meta { display: grid; gap: 2px; }
        .template-title { font-size: 13.5px; font-weight: 600; }
        .template-subtitle { font-size: 12.5px; color: var(--nk-muted); line-height: 1.4; }

        /* Documents */
        .docs-section { padding: 20px; }
        .docs-section-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
        .docs-section-header h2 { margin: 0; font-size: 17px; font-weight: 600; letter-spacing: -0.015em; }
        .docs-section-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .select-all-wrap {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            border-radius: var(--nk-radius-sm);
            border: 1px solid var(--nk-border);
            background: var(--nk-surface);
            font-size: 13px;
            font-weight: 500;
            color: var(--nk-ink-2);
            cursor: pointer;
        }
        .select-all-wrap input, .doc-card-select { accent-color: var(--nk-accent); width: 15px; height: 15px; cursor: pointer; }
        .docs-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
        .doc-card {
            position: relative;
            display: grid;
            grid-template-rows: auto 1fr;
            border-radius: var(--nk-radius);
            border: 1px solid var(--nk-border);
            background: var(--nk-surface);
            overflow: hidden;
            transition: border-color 0.16s ease, box-shadow 0.16s ease;
        }
        .doc-card:hover { border-color: var(--nk-border-2); box-shadow: var(--nk-shadow-lg); }
        .doc-card-select { position: absolute; top: 12px; left: 12px; z-index: 3; }
        .mode-pill {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 3;
            padding: 3px 7px;
            border-radius: 999px;
            border: 1px solid var(--nk-border);
            background: var(--nk-surface);
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--nk-ink-2);
        }
        .doc-preview {
            min-height: 150px;
            background:
                radial-gradient(circle at 1px 1px, color-mix(in srgb, var(--nk-ink) 7%, transparent) 1px, transparent 0) 0 0 / 14px 14px,
                var(--nk-surface-2);
            border-bottom: 1px solid var(--nk-border);
            display: grid;
            place-items: center;
            padding: 22px 18px 18px;
            overflow: hidden;
        }
        .doc-preview.has-image { padding: 14px; align-items: stretch; }
        .doc-preview-frame { width: 100%; height: 100%; display: grid; place-items: center; overflow: hidden; }
        .doc-preview-image {
            display: block;
            width: auto;
            max-width: 100%;
            height: 100%;
            max-height: 122px;
            object-fit: contain;
            border-radius: 3px;
            box-shadow: 0 14px 28px -10px rgba(24, 24, 27, 0.35);
            background: #ffffff;
        }
        .doc-paper { width: 64px; height: 84px; border-radius: 3px; background: #ffffff; box-shadow: 0 14px 28px -10px rgba(24, 24, 27, 0.35); position: relative; }
        .doc-paper::before, .doc-paper::after { content: ""; position: absolute; left: 10px; right: 10px; border-radius: 999px; background: #e4e4e7; }
        .doc-paper::before { top: 14px; height: 5px; }
        .doc-paper::after { top: 26px; height: 32px; border-radius: 3px; background: #f4f4f5; }
        .doc-paper-grid { position: absolute; left: 10px; right: 10px; bottom: 12px; height: 20px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 3px; }
        .doc-paper-grid span { background: #f4f4f5; border-radius: 2px; }
        .doc-paper.ai-mode::before { background: #ddd6fe; }
        .doc-paper.ai-mode::after { background: #f5f3ff; }
        .doc-paper.guided-mode::before { background: #bfdbfe; }
        .doc-paper.guided-mode::after { background: #eff6ff; }
        .doc-body { display: grid; gap: 6px; padding: 14px; align-content: start; }
        .doc-title { font-size: 14px; font-weight: 600; letter-spacing: -0.01em; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .doc-meta { font-size: 11.5px; color: var(--nk-muted); letter-spacing: 0.02em; }
        .doc-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 8px; }
        .doc-actions .button-secondary { width: 100%; }
        .doc-empty {
            grid-column: 1 / -1;
            padding: 36px 20px;
            border-radius: var(--nk-radius);
            border: 1.5px dashed var(--nk-border-2);
            background: var(--nk-surface-2);
            text-align: center;
            color: var(--nk-muted);
            font-size: 14px;
            line-height: 1.5;
        }

        [x-cloak] { display: none !important; }
        /* View switch, trash and per-document menu */
        .view-switch { display: inline-flex; gap: 0; padding: 3px; border: 1px solid var(--nk-border); border-radius: var(--nk-radius-sm); background: var(--nk-surface-3); }
        .view-switch button {
            appearance: none; border: none; background: transparent; color: var(--nk-muted); cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 6px;
            font: inherit; font-size: 13px; font-weight: 500; line-height: 1; transition: background-color 0.16s ease, color 0.16s ease;
        }
        .view-switch button[aria-pressed="true"] { background: var(--nk-surface); color: var(--nk-ink); box-shadow: var(--nk-shadow); }
        .view-switch button:focus-visible { outline: 2px solid var(--nk-accent); outline-offset: 1px; }
        .view-switch svg { width: 15px; height: 15px; }
        .docs-count { font-size: 13px; font-weight: 500; color: var(--nk-muted); margin-left: 8px; }
        .trash-link { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: var(--nk-radius-sm); border: 1px solid var(--nk-border); background: var(--nk-surface); color: var(--nk-ink-2); font-size: 13px; font-weight: 500; text-decoration: none; }
        .trash-link:hover { border-color: var(--nk-border-2); background: var(--nk-surface-2); color: var(--nk-ink); }
        .trash-link svg { width: 15px; height: 15px; }
        .trash-link .count { min-width: 20px; height: 20px; padding: 0 6px; display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; background: var(--nk-surface-3); font-size: 11.5px; font-variant-numeric: tabular-nums; }
        .doc-menu { position: absolute; top: 8px; right: 8px; z-index: 4; }
        .doc-menu-button {
            appearance: none; width: 30px; height: 30px; border-radius: var(--nk-radius-sm); border: 1px solid var(--nk-border);
            background: var(--nk-surface); color: var(--nk-ink-2); cursor: pointer; display: grid; place-items: center; box-shadow: var(--nk-shadow);
        }
        .doc-menu-button:hover { background: var(--nk-surface-2); border-color: var(--nk-border-2); color: var(--nk-ink); }
        .doc-menu-button:focus-visible { outline: 2px solid var(--nk-accent); outline-offset: 1px; }
        .doc-menu-button svg { width: 16px; height: 16px; }
        .doc-menu-list {
            position: absolute; top: calc(100% + 6px); right: 0; min-width: 190px; padding: 4px; margin: 0; list-style: none;
            border-radius: var(--nk-radius); border: 1px solid var(--nk-border); background: var(--nk-surface); box-shadow: var(--nk-shadow-lg);
        }
        .doc-menu-list a, .doc-menu-list button {
            appearance: none; width: 100%; display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: none; border-radius: 6px;
            background: transparent; color: var(--nk-ink-2); font: inherit; font-size: 13.5px; font-weight: 500; text-align: left; text-decoration: none; cursor: pointer;
        }
        .doc-menu-list a:hover, .doc-menu-list button:hover, .doc-menu-list a:focus-visible, .doc-menu-list button:focus-visible { background: var(--nk-surface-2); color: var(--nk-ink); outline: none; }
        .doc-menu-list svg { width: 15px; height: 15px; flex: none; color: var(--nk-muted); }
        .doc-menu-list .danger { color: var(--nk-danger); }
        .doc-menu-list .danger svg { color: var(--nk-danger); }
        .doc-menu-list form { margin: 0; }
        .doc-menu-separator { height: 1px; margin: 4px 6px; background: var(--nk-border); }
        .doc-card.is-menu-open { z-index: 5; }
        .doc-card .mode-pill { right: 46px; }
        .doc-actions { grid-template-columns: 1fr; }

        /* List view */
        .docs-grid.is-list { grid-template-columns: minmax(0, 1fr); gap: 8px; }
        .docs-grid.is-list .doc-card { grid-template-rows: none; grid-template-columns: 72px minmax(0, 1fr); align-items: center; }
        .docs-grid.is-list .doc-preview { min-height: 0; height: 72px; padding: 8px; border-bottom: none; border-right: 1px solid var(--nk-border); }
        .docs-grid.is-list .doc-preview.has-image { padding: 6px; }
        .docs-grid.is-list .doc-preview-image { max-height: 60px; box-shadow: 0 6px 14px -8px rgba(24, 24, 27, 0.4); }
        .docs-grid.is-list .doc-paper { width: 40px; height: 52px; transform: none; }
        .docs-grid.is-list .doc-paper::before { top: 9px; height: 3px; }
        .docs-grid.is-list .doc-paper::after { top: 16px; height: 18px; }
        .docs-grid.is-list .doc-paper-grid { display: none; }
        .docs-grid.is-list .doc-body { display: grid; grid-template-columns: minmax(0, 1fr) auto; grid-template-areas: "title actions" "meta actions"; column-gap: 16px; row-gap: 2px; padding: 10px 56px 10px 14px; }
        .docs-grid.is-list .doc-title { grid-area: title; }
        .docs-grid.is-list .doc-meta { grid-area: meta; }
        .docs-grid.is-list .doc-actions { grid-area: actions; margin-top: 0; align-self: center; }
        .docs-grid.is-list .doc-actions .doc-link { padding: 7px 14px; }
        .docs-grid.is-list .doc-card-select { top: 50%; left: 14px; transform: translateY(-50%); }
        .docs-grid.is-list .doc-preview { margin-left: 34px; border-right: none; }
        .docs-grid.is-list .doc-card { grid-template-columns: 106px minmax(0, 1fr); }
        .docs-grid.is-list .mode-pill { top: 50%; right: 52px; transform: translateY(-50%); }
        .docs-grid.is-list .doc-menu { top: 50%; right: 12px; transform: translateY(-50%); }
        .docs-grid.is-list .doc-empty { grid-column: 1; }
        @media (max-width: 560px) {
            .docs-grid.is-list .doc-body { grid-template-columns: minmax(0, 1fr); grid-template-areas: "title" "meta" "actions"; padding-right: 48px; }
            .docs-grid.is-list .doc-actions { justify-self: start; margin-top: 6px; }
        }

        /* Fillable forms */
        .forms-header p { max-width: 62ch; }
        .forms-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 14px; }
        .form-card { display: grid; grid-template-rows: auto 1fr; border-radius: var(--nk-radius); border: 1px solid var(--nk-border); background: var(--nk-surface); overflow: hidden; transition: border-color 0.16s ease, box-shadow 0.16s ease; }
        .form-card:hover { border-color: var(--nk-border-2); box-shadow: var(--nk-shadow-lg); }
        .form-card-preview {
            position: relative; display: block; height: 132px; padding: 14px 18px 0; overflow: hidden; border-bottom: 1px solid var(--nk-border);
            background:
                radial-gradient(circle at 1px 1px, color-mix(in srgb, var(--nk-ink) 7%, transparent) 1px, transparent 0) 0 0 / 14px 14px,
                var(--nk-surface-2);
        }
        .form-card-preview img { display: block; width: 100%; height: auto; border-radius: 3px 3px 0 0; box-shadow: 0 12px 24px -10px rgba(24, 24, 27, 0.4); background: #fff; }
        .form-card-preview:focus-visible { outline: 2px solid var(--nk-accent); outline-offset: -2px; }
        .form-popular { position: absolute; top: 8px; right: 8px; padding: 3px 7px; border-radius: 999px; background: var(--nk-accent); color: #fff; font-size: 10px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; }
        .form-card-body { display: grid; gap: 3px; padding: 12px; align-content: start; }
        .form-card-title { display: flex; align-items: baseline; gap: 6px; font-size: 14px; font-weight: 600; letter-spacing: -0.01em; }
        .form-card-title small { font-size: 12px; font-weight: 500; color: var(--nk-muted-2); }
        .form-card-subtitle { font-size: 12.5px; color: var(--nk-muted); line-height: 1.4; min-height: 2.8em; }
        .form-card-meta { font-size: 11.5px; color: var(--nk-muted); }
        .form-card-actions { display: grid; grid-template-columns: 1fr auto; gap: 6px; margin-top: 8px; }
        .form-card-actions .button-secondary { padding-inline: 10px; }
        @media (max-width: 1080px) { .forms-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 720px) { .forms-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 460px) { .forms-grid { grid-template-columns: minmax(0, 1fr); } }

        /* Upload limit modal */
        .limit-modal { position: fixed; inset: 0; z-index: 100000; background: rgba(9, 9, 11, 0.55); display: none; align-items: center; justify-content: center; padding: 20px; }
        .limit-modal-card { width: min(440px, 94vw); padding: 22px; border-radius: var(--nk-radius); background: var(--nk-surface); border: 1px solid var(--nk-border); box-shadow: var(--nk-shadow-lg); }
        .limit-modal-title { margin: 0 0 8px; font-size: 18px; font-weight: 600; letter-spacing: -0.015em; }
        .limit-modal-copy { margin: 0 0 16px; color: var(--nk-muted); font-size: 14px; line-height: 1.55; }
        .limit-modal-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .limit-modal-actions a { text-decoration: none; }

        @media (max-width: 960px) {
            .workspace-grid { grid-template-columns: minmax(0, 1fr); }
            .docs-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (max-width: 720px) {
            .uploader-page { padding-top: 100px; }
            .docs-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .template-category-grid, .template-group-grid { grid-template-columns: minmax(0, 1fr); }
        }
        @media (max-width: 440px) {
            .docs-grid { grid-template-columns: minmax(0, 1fr); }
            .upload-meta { flex-direction: column; align-items: stretch; }
            .upload-file-name { min-width: 0; }
        }
        @media (prefers-reduced-motion: reduce) {
            .upload-dropzone, .doc-card, .template-preview, .template-category-card { transition: none; }
        }
    </style>
</head>
    <body class="min-h-screen antialiased">
        <x-site-header />

        <main class="uploader-page">
            <div class="page-container">
                <div class="dashboard-shell">
                    <div class="page-intro">
                        <div class="eyebrow">PDF editor</div>
                        <h1>Open a PDF, or start a new one.</h1>
                        <p>Upload a file to edit its text, sign it, mark it up or convert it. Or begin from a blank page or a guided template.</p>
                    </div>

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
                            <div class="card-header">
                                <h2>Start from a blank page</h2>
                                <p>Pick a page size and orientation; the editor opens on an empty document.</p>
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

                                <button type="submit" class="button-primary">Create blank PDF</button>
                            </form>
                        </div>

                        <div class="section-card template-pane" id="templates-gallery">
                            <div>
                                <div class="card-header">
                                    <h2>Guided templates</h2>
                                    <p>Start from a layout and fill in the details step by step.</p>
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

                    @if ($fillableForms->isNotEmpty())
                        <section class="section-card forms-section" id="fillable-forms">
                            <div class="card-header forms-header">
                                <div>
                                    <h2>Fillable forms</h2>
                                    <p>Official forms and ready-made templates with their own fields. Fill out now opens one in the editor as a new document of yours.</p>
                                </div>
                            </div>
                            <div class="forms-grid">
                                @foreach ($fillableForms as $form)
                                    <article class="form-card">
                                        <a href="{{ route('forms.show', $form['slug']) }}" class="form-card-preview" aria-label="About {{ $form['title'] }}">
                                            @if ($form['preview'])
                                                <img src="{{ asset($form['preview']) }}" alt="" loading="lazy" width="1224" height="1584">
                                            @endif
                                            @if (!empty($form['popular']))
                                                <span class="form-popular">Popular</span>
                                            @endif
                                        </a>
                                        <div class="form-card-body">
                                            <div class="form-card-title">{{ $form['title'] }} <small>{{ $form['year'] }}</small></div>
                                            <div class="form-card-subtitle">{{ $form['subtitle'] }}</div>
                                            <div class="form-card-meta">{{ $form['fields'] }} fields &middot; {{ $form['pages'] }} {{ Str::plural('page', $form['pages']) }} &middot; {{ $form['issuer'] }}</div>
                                            <div class="form-card-actions">
                                                <form action="{{ route('forms.fill', $form['slug']) }}" method="POST" style="margin:0;">
                                                    @csrf
                                                    <button type="submit" class="button-primary" style="width:100%;">Fill out now</button>
                                                </form>
                                                <a href="{{ route('forms.show', $form['slug']) }}" class="button-secondary">Details</a>
                                            </div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    <section class="docs-section">
                        <div class="docs-section-header">
                            <h2>{{ $showTrash ? 'Trash' : 'Your documents' }}<span class="docs-count">{{ $documents->total() }}</span></h2>
                            <div class="docs-section-actions">
                                @if (!$showTrash && $documents->count() > 0)
                                    <label class="select-all-wrap" for="select-all-checkbox">
                                        <input type="checkbox" id="select-all-checkbox" onchange="toggleSelectAll(this)">
                                        <span>Select all</span>
                                    </label>
                                    <button id="bulk-delete-btn" class="button-danger" style="display:none;" onclick="submitBulkDelete()">
                                        Move selected to trash (<span id="selected-count">0</span>)
                                    </button>
                                @endif
                                @if ($showTrash)
                                    <a href="{{ route('documents.index') }}" class="trash-link">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
                                        Back to documents
                                    </a>
                                    @if ($documents->count() > 0)
                                        <form action="{{ route('documents.emptyTrash') }}" method="POST" style="margin:0;" onsubmit="return confirm('Delete every document in the trash permanently? This cannot be undone.')">
                                            @csrf
                                            <button type="submit" class="button-danger">Empty trash</button>
                                        </form>
                                    @endif
                                @else
                                    <a href="{{ route('documents.index', ['view' => 'trash']) }}" class="trash-link" aria-label="Open the trash ({{ $trashCount }})">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1L5 6" /></svg>
                                        Trash
                                        <span class="count">{{ $trashCount }}</span>
                                    </a>
                                @endif
                                <div class="view-switch" role="group" aria-label="View">
                                    <button type="button" data-docs-view="grid" aria-pressed="true" title="Grid view">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" /></svg>
                                        Grid
                                    </button>
                                    <button type="button" data-docs-view="list" aria-pressed="false" title="List view">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
                                        List
                                    </button>
                                </div>
                            </div>
                        </div>

                        <form id="bulk-delete-form" action="{{ route('documents.bulkDestroy') }}" method="POST" style="display:none;">
                            @csrf
                        </form>

                        <div class="docs-grid" id="docs-grid" data-view-mode="{{ $showTrash ? 'trash' : 'documents' }}">
                            @forelse ($documents as $document)
                                @php
                                    $editUrl = $document->mode === 'guided'
                                        ? route('documents.guided', $document)
                                        : ($document->mode === 'ai'
                                            ? route('documents.ai', $document)
                                            : route('documents.editPdfjs', $document));
                                    $sizeMb = $document->size_bytes > 0 ? number_format($document->size_bytes / (1024 * 1024), 1) : '0.0';
                                    $updatedLabel = optional($document->updated_at)->diffForHumans() ?: 'just now';
                                    $trashedLabel = optional($document->deleted_at)->diffForHumans() ?: 'just now';
                                    $paperClass = $document->mode === 'ai' ? 'ai-mode' : ($document->mode === 'guided' ? 'guided-mode' : '');
                                    // A cacheable URL, not the image inlined as base64 (App\Services\DocumentPreviews).
                                    $previewDataUrl = app(\App\Services\DocumentPreviews::class)->url($document);
                                @endphp
                                <div class="doc-card" x-data="{ menuOpen: false }" :class="{ 'is-menu-open': menuOpen }" @keydown.escape.window="menuOpen = false">
                                    @unless ($showTrash)
                                        <input type="checkbox" class="doc-card-select doc-checkbox" value="{{ $document->id }}" onchange="updateBulkState()" aria-label="Select {{ $document->original_name }}">
                                    @endunless
                                    <div class="doc-menu" @click.away="menuOpen = false">
                                        <button type="button" class="doc-menu-button" @click="menuOpen = !menuOpen" :aria-expanded="menuOpen ? 'true' : 'false'" aria-haspopup="menu" aria-label="More options for {{ $document->original_name }}">
                                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="19" cy="12" r="1.8" /></svg>
                                        </button>
                                        <ul class="doc-menu-list" role="menu" x-show="menuOpen" x-cloak x-transition.opacity.duration.120ms>
                                            @if ($showTrash)
                                                <li role="none">
                                                    <form action="{{ route('documents.restore', $document) }}" method="POST">
                                                        @csrf
                                                        <button type="submit" role="menuitem">
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7M3 4v5h5" /></svg>
                                                            Restore
                                                        </button>
                                                    </form>
                                                </li>
                                                <li role="none">
                                                    <a href="{{ route('documents.download', $document) }}" role="menuitem">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" /></svg>
                                                        Download
                                                    </a>
                                                </li>
                                                <li role="none" class="doc-menu-separator" aria-hidden="true"></li>
                                                <li role="none">
                                                    <form action="{{ route('documents.destroy', $document) }}" method="POST" onsubmit="return confirm('Delete this document permanently? This cannot be undone.')">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" role="menuitem" class="danger">
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1L5 6M10 11v6M14 11v6" /></svg>
                                                            Delete permanently
                                                        </button>
                                                    </form>
                                                </li>
                                            @else
                                                <li role="none">
                                                    <a href="{{ $editUrl }}" role="menuitem">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3h7v7M21 3l-9 9M19 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h5" /></svg>
                                                        Open
                                                    </a>
                                                </li>
                                                <li role="none">
                                                    <a href="{{ route('documents.download', $document) }}" role="menuitem">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" /></svg>
                                                        Download
                                                    </a>
                                                </li>
                                                <li role="none" class="doc-menu-separator" aria-hidden="true"></li>
                                                <li role="none">
                                                    <form action="{{ route('documents.trash', $document) }}" method="POST">
                                                        @csrf
                                                        <button type="submit" role="menuitem" class="danger">
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1L5 6" /></svg>
                                                            Move to trash
                                                        </button>
                                                    </form>
                                                </li>
                                            @endif
                                        </ul>
                                    </div>
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
                                                    decoding="async"
                                                    @if ($document->preview_image_width && $document->preview_image_height)
                                                        width="{{ $document->preview_image_width }}" height="{{ $document->preview_image_height }}"
                                                    @endif
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
                                        <div class="doc-meta">{{ $showTrash ? 'Trashed ' . $trashedLabel : 'Edited ' . $updatedLabel }} &middot; {{ $sizeMb }} MB</div>
                                        <div class="doc-actions">
                                            @if ($showTrash)
                                                <form action="{{ route('documents.restore', $document) }}" method="POST" style="margin:0;">
                                                    @csrf
                                                    <button class="button-secondary" type="submit" style="width:100%;">Restore</button>
                                                </form>
                                            @else
                                                <a href="{{ $editUrl }}" class="doc-link">Open</a>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="doc-empty">
                                    @if ($showTrash)
                                        The trash is empty.
                                    @else
                                        Nothing here yet. Upload a PDF, start from a blank page or pick a template, and it will show up here.
                                    @endif
                                </div>
                            @endforelse
                        </div>

                        @if ($documents->hasPages())
                            <nav class="docs-pagination" aria-label="Pages of documents">
                                @if ($documents->onFirstPage())
                                    <span class="button-secondary is-disabled" aria-disabled="true">Previous</span>
                                @else
                                    <a class="button-secondary" href="{{ $documents->previousPageUrl() }}" rel="prev">Previous</a>
                                @endif
                                <span class="docs-pagination-status">Page {{ $documents->currentPage() }} of {{ $documents->lastPage() }}</span>
                                @if ($documents->hasMorePages())
                                    <a class="button-secondary" href="{{ $documents->nextPageUrl() }}" rel="next">Next</a>
                                @else
                                    <span class="button-secondary is-disabled" aria-disabled="true">Next</span>
                                @endif
                            </nav>
                        @endif
                    </section>
                </div>
            </div>
        </main>

        <div id="pdf-upload-limit-modal" class="limit-modal" aria-hidden="true">
            <div class="limit-modal-card">
                <h3 class="limit-modal-title">Out of PDF uploads</h3>
                <p class="limit-modal-copy">You are out of PDF uploads for this month. Please look at the subscription plans to continue.</p>
                <div class="limit-modal-actions">
                    <a href="/portal/subscription"><button type="button" class="button-primary">View subscription plans</button></a>
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
                if (!confirm('Move the selected documents to the trash?')) return;

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

            function initDocsViewSwitch() {
                const grid = document.getElementById('docs-grid');
                const buttons = Array.from(document.querySelectorAll('[data-docs-view]'));
                if (!grid || buttons.length === 0) return;
                const STORAGE_KEY = 'netkit-docs-view';
                const apply = (view) => {
                    const mode = view === 'list' ? 'list' : 'grid';
                    grid.classList.toggle('is-list', mode === 'list');
                    buttons.forEach((button) => button.setAttribute('aria-pressed', button.dataset.docsView === mode ? 'true' : 'false'));
                    try { localStorage.setItem(STORAGE_KEY, mode); } catch (_) {}
                };
                let saved = 'grid';
                try { saved = localStorage.getItem(STORAGE_KEY) || 'grid'; } catch (_) {}
                apply(saved);
                buttons.forEach((button) => button.addEventListener('click', () => apply(button.dataset.docsView)));
            }

            document.addEventListener('DOMContentLoaded', () => {
                updateBulkState();
                initUploadDropzone();
                initTemplateWorkflow();
                initDocsViewSwitch();

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
