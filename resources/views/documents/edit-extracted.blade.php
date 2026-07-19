<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/netkit_logo_cube.svg') }}">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Edit Extracted Text - {{ $document->original_name }}</title>
    <style>
        :root {
            --bg: #070b12;
            --panel: #111824;
            --ink: #e9f0ff;
            --muted: #93a4bf;
            --accent: #6ee7b7;
            --danger: #ff6b6b;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
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
            background: var(--panel);
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .header-left h1 {
            font-size: 18px;
            font-weight: 600;
        }
        .stats {
            display: flex;
            gap: 16px;
            font-size: 13px;
            color: var(--muted);
        }
        .stat {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
        }
        .btn-primary {
            background: var(--accent);
            color: #0b2d20;
        }
        .btn-secondary {
            background: transparent;
            border: 1px solid rgba(255,255,255,0.2);
            color: var(--ink);
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        .document-view {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .page {
            position: relative;
            background: white;
            min-height: 1000px;
            padding: 60px 80px;
            border-bottom: 2px solid #e0e0e0;
        }
        .page:last-child {
            border-bottom: none;
        }
        .page-header {
            text-align: center;
            padding: 20px;
            background: #f5f5f5;
            color: #666;
            font-size: 13px;
            font-weight: 600;
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
        .save-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--panel);
            border-top: 1px solid rgba(255,255,255,0.08);
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.3);
            display: none;
        }
        .save-bar.visible {
            display: flex;
        }
        .modified-count {
            color: var(--accent);
            font-weight: 600;
        }
        .loading {
            text-align: center;
            padding: 100px;
            font-size: 18px;
            color: var(--muted);
        }
    </style>
</head>
<body>
    <header>
        <div class="header-left">
            <h1>📄 {{ $document->original_name }}</h1>
            <div class="stats">
                <div class="stat">
                    <span>📑</span>
                    <span>{{ $extraction->total_pages }} pages</span>
                </div>
                <div class="stat">
                    <span>📝</span>
                    <span>{{ $extraction->total_words }} words</span>
                </div>
            </div>
        </div>
        <div class="header-left">
            <button class="btn btn-secondary" onclick="window.close()">Close</button>
            <button class="btn btn-primary" id="save-btn">Save Changes</button>
        </div>
    </header>

    <div class="container">
        <div class="document-view" id="document-view">
            <div class="loading">Loading extracted text...</div>
        </div>
    </div>

    <div class="save-bar" id="save-bar">
        <div>
            <span class="modified-count" id="modified-count">0 text blocks modified</span>
        </div>
        <div class="header-left">
            <button class="btn btn-secondary" onclick="resetChanges()">Reset All</button>
            <button class="btn btn-primary" onclick="saveChanges()">Save to PDF</button>
        </div>
    </div>

    <script>
        const extractionData = @json($extractionData);
        const documentId = {{ $document->id }};
        const modifiedTexts = new Map();

        console.log('Extraction data:', extractionData);
        console.log('Pages:', extractionData ? extractionData.length : 'null');

        function renderDocument() {
            const container = document.getElementById('document-view');
            container.innerHTML = '';

            if (!extractionData || extractionData.length === 0) {
                container.innerHTML = '<div class="loading">No extraction data found. Please wait for processing to complete.</div>';
                return;
            }

            extractionData.forEach((pageData, pageIndex) => {
                const pageDiv = document.createElement('div');
                pageDiv.className = 'page';
                pageDiv.style.width = pageData.width + 'px';
                pageDiv.style.height = pageData.height + 'px';

                const pageHeader = document.createElement('div');
                pageHeader.className = 'page-header';
                pageHeader.textContent = `Page ${pageData.page_number} of ${extractionData.length}`;
                container.appendChild(pageHeader);

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
                            updateModifiedCount();
                        } else if (newText === word.text && span.classList.contains('modified')) {
                            span.classList.remove('modified');
                            modifiedTexts.delete(wordKey);
                            updateModifiedCount();
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

        function updateModifiedCount() {
            const count = modifiedTexts.size;
            const countEl = document.getElementById('modified-count');
            const saveBar = document.getElementById('save-bar');
            
            countEl.textContent = `${count} text block${count !== 1 ? 's' : ''} modified`;
            
            if (count > 0) {
                saveBar.classList.add('visible');
            } else {
                saveBar.classList.remove('visible');
            }
        }

        function resetChanges() {
            if (!confirm('Reset all changes?')) return;
            modifiedTexts.clear();
            renderDocument();
            updateModifiedCount();
        }

        async function saveChanges() {
            if (modifiedTexts.size === 0) {
                alert('No changes to save');
                return;
            }

            const saveBtn = document.getElementById('save-btn');
            saveBtn.textContent = 'Saving...';
            saveBtn.disabled = true;

            try {
                const response = await fetch(`/documents/${documentId}/save-extracted`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        modifications: Array.from(modifiedTexts.values())
                    })
                });

                if (response.ok) {
                    alert('✓ Changes saved successfully!');
                    modifiedTexts.clear();
                    updateModifiedCount();
                } else {
                    alert('✗ Failed to save changes');
                }
            } catch (error) {
                console.error('Save error:', error);
                alert('✗ Error saving changes');
            } finally {
                saveBtn.textContent = 'Save Changes';
                saveBtn.disabled = false;
            }
        }

        // Initialize
        renderDocument();
    </script>
</body>
</html>
