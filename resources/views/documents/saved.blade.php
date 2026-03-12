<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Saved Redaction Preview</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f4efe6;
            --panel: #fffdf9;
            --ink: #1f2937;
            --muted: #6b7280;
            --line: #d6cfc3;
            --accent: #c2410c;
            --accent-soft: rgba(194, 65, 12, 0.12);
            --accent-strong: #9a3412;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            background:
                radial-gradient(circle at top left, rgba(194, 65, 12, 0.08), transparent 30%),
                linear-gradient(180deg, #f7f2ea 0%, var(--bg) 100%);
            color: var(--ink);
        }

        .wrap {
            max-width: 1320px;
            margin: 0 auto;
            padding: 32px 20px 40px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 24px;
        }

        .eyebrow {
            margin: 0 0 8px;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 0.12em;
            font-size: 12px;
            font-weight: 700;
        }

        h1 {
            margin: 0;
            font-size: clamp(28px, 4vw, 42px);
            line-height: 1;
        }

        .sub {
            margin: 10px 0 0;
            color: var(--muted);
            max-width: 840px;
            font-size: 15px;
            line-height: 1.5;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 16px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: var(--panel);
            color: var(--ink);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }

        .btn.primary {
            border-color: var(--accent);
            background: var(--accent);
            color: #fff7ed;
        }

        .layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 320px;
            gap: 20px;
            align-items: start;
        }

        .meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin-bottom: 22px;
        }

        .meta-card, .empty, .panel, .history-panel {
            border: 1px solid var(--line);
            background: var(--panel);
            border-radius: 20px;
            box-shadow: 0 18px 40px rgba(76, 54, 35, 0.06);
        }

        .meta-card {
            padding: 14px 16px;
        }

        .meta-label {
            margin: 0 0 4px;
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .meta-value {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            word-break: break-word;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 18px;
        }

        .panel {
            padding: 16px;
        }

        .panel h2, .history-panel h2 {
            margin: 0 0 12px;
            font-size: 18px;
        }

        .preview-frame {
            position: relative;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid var(--line);
            background: #ece5d8;
        }

        .preview-trigger {
            display: block;
            cursor: zoom-in;
        }

        .preview-frame img {
            display: block;
            width: 100%;
            height: auto;
        }

        .focus-box {
            position: absolute;
            border: 2px solid var(--accent);
            background: var(--accent-soft);
            box-shadow: 0 0 0 9999px rgba(255, 255, 255, 0.06);
            pointer-events: none;
        }

        .empty {
            padding: 28px;
        }

        .empty p {
            margin: 0;
            color: var(--muted);
            line-height: 1.5;
        }

        .history-panel {
            padding: 16px;
            position: sticky;
            top: 20px;
        }

        .history-list {
            display: grid;
            gap: 10px;
        }

        .history-item {
            display: block;
            text-decoration: none;
            color: inherit;
            padding: 12px 13px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: #f9f5ef;
            transition: border-color 0.15s, background 0.15s, transform 0.15s;
        }

        .history-item:hover {
            border-color: var(--accent);
            transform: translateY(-1px);
        }

        .history-item.active {
            border-color: var(--accent-strong);
            background: #fff1e8;
            box-shadow: inset 0 0 0 1px rgba(154, 52, 18, 0.14);
        }

        .history-time {
            margin: 0 0 6px;
            font-size: 12px;
            color: var(--muted);
        }

        .history-change {
            margin: 0;
            font-size: 14px;
            line-height: 1.35;
            font-weight: 700;
            word-break: break-word;
        }

        .history-page {
            margin: 6px 0 0;
            font-size: 12px;
            color: var(--muted);
        }

        .zoom-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(25, 18, 11, 0.84);
            backdrop-filter: blur(4px);
            z-index: 1000;
        }

        .zoom-modal.active {
            display: flex;
        }

        .zoom-card {
            position: relative;
            max-width: min(1400px, 96vw);
            max-height: 92vh;
            background: #fffdf9;
            border-radius: 18px;
            padding: 14px;
            border: 1px solid rgba(255,255,255,0.28);
            box-shadow: 0 30px 90px rgba(0,0,0,0.34);
        }

        .zoom-card img {
            display: block;
            max-width: min(1360px, 92vw);
            max-height: 82vh;
            width: auto;
            height: auto;
            border-radius: 12px;
        }

        .zoom-close {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 36px;
            height: 36px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: rgba(255,255,255,0.92);
            color: var(--ink);
            font-size: 20px;
            cursor: pointer;
        }

        .zoom-caption {
            margin: 10px 4px 0;
            color: var(--muted);
            font-size: 13px;
        }

        .hover-preview {
            position: fixed;
            right: 20px;
            bottom: 20px;
            z-index: 999;
            display: none;
            width: min(460px, 42vw);
            pointer-events: none;
        }

        .hover-preview.active {
            display: block;
        }

        .hover-preview-card {
            background: rgba(255, 253, 249, 0.98);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 12px;
            box-shadow: 0 28px 70px rgba(25, 18, 11, 0.28);
        }

        .hover-preview-label {
            margin: 0 0 10px;
            color: var(--accent-strong);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .hover-preview-frame {
            position: relative;
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid var(--line);
            background: #ece5d8;
        }

        .hover-preview-frame img {
            display: block;
            width: 100%;
            height: auto;
        }

        .hover-focus-box {
            border: 3px solid #dc2626;
            background: rgba(220, 38, 38, 0.16);
            box-shadow: 0 0 0 9999px rgba(255, 255, 255, 0.03);
        }

        @media (max-width: 980px) {
            .layout {
                grid-template-columns: 1fr;
            }

            .history-panel {
                position: static;
            }
        }

        @media (max-width: 720px) {
            .topbar {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="topbar">
            <div>
                <p class="eyebrow">Surgical Save Preview</p>
                <h1>Saved Redactions</h1>
                <p class="sub">Click any image to open a larger view. Use the history list to inspect earlier live-save redactions for document {{ $document->id }}.</p>
            </div>
            <div class="actions">
                <a class="btn" href="{{ route('documents.edit', $document) }}">Back To Editor</a>
                @if ($preview)
                    <a class="btn primary" href="{{ route('documents.savedEditImage', ['document' => $document, 'variant' => 'redacted']) }}?entry={{ urlencode((string) ($preview['save_id'] ?? 'latest')) }}" target="_blank" rel="noopener">Open Redacted Image</a>
                @endif
            </div>
        </div>

        @if (!$preview)
            <div class="empty">
                <p>No live-save preview exists yet for this document. Make one surgical overlay edit, click <strong>Save PDF</strong>, then reload this page.</p>
            </div>
        @else
            @php($entryId = (string) ($preview['save_id'] ?? $selectedEntry ?? 'latest'))
            @php($highlight = $preview['highlight'] ?? [])
            @php($beforeUrl = route('documents.savedEditImage', ['document' => $document, 'variant' => 'before']) . '?entry=' . urlencode($entryId) . '&v=' . urlencode($preview['created_at'] ?? now()))
            @php($redactedUrl = route('documents.savedEditImage', ['document' => $document, 'variant' => 'redacted']) . '?entry=' . urlencode($entryId) . '&v=' . urlencode($preview['created_at'] ?? now()))
            @php($finalUrl = route('documents.savedEditImage', ['document' => $document, 'variant' => 'final']) . '?entry=' . urlencode($entryId) . '&v=' . urlencode($preview['created_at'] ?? now()))

            <div class="layout">
                <main>
                    <div class="meta">
                        <div class="meta-card">
                            <p class="meta-label">Page</p>
                            <p class="meta-value">{{ $preview['page_number'] ?? '?' }}</p>
                        </div>
                        <div class="meta-card">
                            <p class="meta-label">Original</p>
                            <p class="meta-value">{{ (($preview['original_text'] ?? '') === '') ? '[deleted]' : ($preview['original_text'] ?? '') }}</p>
                        </div>
                        <div class="meta-card">
                            <p class="meta-label">Saved</p>
                            <p class="meta-value">{{ (($preview['new_text'] ?? '') === '') ? '[deleted]' : ($preview['new_text'] ?? '') }}</p>
                        </div>
                        <div class="meta-card">
                            <p class="meta-label">Updated</p>
                            <p class="meta-value">{{ $preview['created_at'] ?? '' }}</p>
                        </div>
                    </div>

                    <div class="grid">
                        <section class="panel">
                            <h2>Before Redaction</h2>
                            <div class="preview-frame">
                                <a class="preview-trigger" href="{{ $beforeUrl }}" data-full-image="{{ $beforeUrl }}" data-caption="Before Redaction" data-highlight-left="{{ $highlight['left_pct'] ?? 0 }}" data-highlight-top="{{ $highlight['top_pct'] ?? 0 }}" data-highlight-width="{{ $highlight['width_pct'] ?? 0 }}" data-highlight-height="{{ $highlight['height_pct'] ?? 0 }}">
                                    <img src="{{ $beforeUrl }}" alt="Before save preview">
                                </a>
                                <div class="focus-box" style="left: {{ $highlight['left_pct'] ?? 0 }}%; top: {{ $highlight['top_pct'] ?? 0 }}%; width: {{ $highlight['width_pct'] ?? 0 }}%; height: {{ $highlight['height_pct'] ?? 0 }}%;"></div>
                            </div>
                        </section>

                        <section class="panel">
                            <h2>After Redaction</h2>
                            <div class="preview-frame">
                                <a class="preview-trigger" href="{{ $redactedUrl }}" data-full-image="{{ $redactedUrl }}" data-caption="After Redaction" data-highlight-left="{{ $highlight['left_pct'] ?? 0 }}" data-highlight-top="{{ $highlight['top_pct'] ?? 0 }}" data-highlight-width="{{ $highlight['width_pct'] ?? 0 }}" data-highlight-height="{{ $highlight['height_pct'] ?? 0 }}">
                                    <img src="{{ $redactedUrl }}" alt="After redaction preview">
                                </a>
                                <div class="focus-box" style="left: {{ $highlight['left_pct'] ?? 0 }}%; top: {{ $highlight['top_pct'] ?? 0 }}%; width: {{ $highlight['width_pct'] ?? 0 }}%; height: {{ $highlight['height_pct'] ?? 0 }}%;"></div>
                            </div>
                        </section>

                        <section class="panel">
                            <h2>Final Saved Result</h2>
                            <div class="preview-frame">
                                <a class="preview-trigger" href="{{ $finalUrl }}" data-full-image="{{ $finalUrl }}" data-caption="Final Saved Result" data-highlight-left="{{ $highlight['left_pct'] ?? 0 }}" data-highlight-top="{{ $highlight['top_pct'] ?? 0 }}" data-highlight-width="{{ $highlight['width_pct'] ?? 0 }}" data-highlight-height="{{ $highlight['height_pct'] ?? 0 }}">
                                    <img src="{{ $finalUrl }}" alt="Final saved preview">
                                </a>
                                <div class="focus-box" style="left: {{ $highlight['left_pct'] ?? 0 }}%; top: {{ $highlight['top_pct'] ?? 0 }}%; width: {{ $highlight['width_pct'] ?? 0 }}%; height: {{ $highlight['height_pct'] ?? 0 }}%;"></div>
                            </div>
                        </section>
                    </div>
                </main>

                <aside class="history-panel">
                    <h2>Previous Saves</h2>
                    @if (empty($previewHistory))
                        <p class="history-time">No history yet.</p>
                    @else
                        <div class="history-list">
                            @foreach ($previewHistory as $entry)
                                @php($historyEntryId = (string) ($entry['save_id'] ?? ''))
                                <a href="{{ route('documents.savedEdit', $document) }}?entry={{ urlencode($historyEntryId) }}" class="history-item{{ $historyEntryId === $entryId ? ' active' : '' }}">
                                    <p class="history-time">{{ $entry['created_at'] ?? '' }}</p>
                                    <p class="history-change">{{ (($entry['original_text'] ?? '') === '') ? '[deleted]' : ($entry['original_text'] ?? '') }} -> {{ (($entry['new_text'] ?? '') === '') ? '[deleted]' : ($entry['new_text'] ?? '') }}</p>
                                    <p class="history-page">Page {{ $entry['page_number'] ?? '?' }}</p>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </aside>
            </div>
        @endif
    </div>

    <div id="zoom-modal" class="zoom-modal" aria-hidden="true">
        <div class="zoom-card">
            <button type="button" id="zoom-close" class="zoom-close" aria-label="Close image preview">&times;</button>
            <img id="zoom-image" src="" alt="Expanded preview">
            <div id="zoom-caption" class="zoom-caption"></div>
        </div>
    </div>

    <div id="hover-preview" class="hover-preview" aria-hidden="true">
        <div class="hover-preview-card">
            <div id="hover-preview-label" class="hover-preview-label"></div>
            <div class="hover-preview-frame">
                <img id="hover-preview-image" src="" alt="Hover preview">
                <div id="hover-preview-box" class="focus-box hover-focus-box"></div>
            </div>
        </div>
    </div>

    <script>
        const zoomModal = document.getElementById('zoom-modal');
        const zoomImage = document.getElementById('zoom-image');
        const zoomCaption = document.getElementById('zoom-caption');
        const zoomClose = document.getElementById('zoom-close');
        const hoverPreview = document.getElementById('hover-preview');
        const hoverPreviewImage = document.getElementById('hover-preview-image');
        const hoverPreviewBox = document.getElementById('hover-preview-box');
        const hoverPreviewLabel = document.getElementById('hover-preview-label');

        function showHoverPreview(link) {
            hoverPreviewImage.src = link.dataset.fullImage || link.href;
            hoverPreviewLabel.textContent = (link.dataset.caption || 'Preview') + ' Focus';
            hoverPreviewBox.style.left = `${link.dataset.highlightLeft || 0}%`;
            hoverPreviewBox.style.top = `${link.dataset.highlightTop || 0}%`;
            hoverPreviewBox.style.width = `${link.dataset.highlightWidth || 0}%`;
            hoverPreviewBox.style.height = `${link.dataset.highlightHeight || 0}%`;
            hoverPreview.classList.add('active');
            hoverPreview.setAttribute('aria-hidden', 'false');
        }

        function hideHoverPreview() {
            hoverPreview.classList.remove('active');
            hoverPreview.setAttribute('aria-hidden', 'true');
            hoverPreviewImage.src = '';
            hoverPreviewLabel.textContent = '';
        }

        document.querySelectorAll('.preview-trigger').forEach((link) => {
            link.addEventListener('click', (event) => {
                event.preventDefault();
                zoomImage.src = link.dataset.fullImage || link.href;
                zoomCaption.textContent = link.dataset.caption || '';
                zoomModal.classList.add('active');
                zoomModal.setAttribute('aria-hidden', 'false');
            });
            link.addEventListener('mouseenter', () => showHoverPreview(link));
            link.addEventListener('mouseleave', hideHoverPreview);
            link.addEventListener('focus', () => showHoverPreview(link));
            link.addEventListener('blur', hideHoverPreview);
        });

        function closeZoomModal() {
            zoomModal.classList.remove('active');
            zoomModal.setAttribute('aria-hidden', 'true');
            zoomImage.src = '';
            zoomCaption.textContent = '';
        }

        zoomClose.addEventListener('click', closeZoomModal);
        zoomModal.addEventListener('click', (event) => {
            if (event.target === zoomModal) {
                closeZoomModal();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && zoomModal.classList.contains('active')) {
                closeZoomModal();
            }
        });
    </script>
</body>
</html>
