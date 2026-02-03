<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>PDF Uploader</title>
        <style>
            :root {
                color-scheme: light;
                --bg: #0b1320;
                --card: #141f2e;
                --ink: #e9f0ff;
                --muted: #a9b7cf;
                --accent: #4dd0a8;
                --danger: #ff6b6b;
            }
            * { box-sizing: border-box; }
            body {
                margin: 0;
                font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
                background: radial-gradient(circle at top, #19283d, var(--bg));
                color: var(--ink);
                min-height: 100vh;
            }
            .shell {
                max-width: 1000px;
                margin: 0 auto;
                padding: 48px 20px 72px;
            }
            h1 {
                margin: 0 0 8px;
                font-size: 32px;
                letter-spacing: 0.5px;
            }
            p { color: var(--muted); }
            .card {
                background: var(--card);
                border: 1px solid rgba(255,255,255,0.08);
                border-radius: 18px;
                padding: 24px;
                margin-top: 24px;
                box-shadow: 0 20px 40px rgba(0,0,0,0.25);
            }
            .upload {
                display: flex;
                gap: 16px;
                flex-wrap: wrap;
                align-items: center;
            }
            input[type="file"] {
                background: #0f1826;
                border: 1px dashed rgba(255,255,255,0.25);
                color: var(--ink);
                padding: 12px;
                border-radius: 10px;
                width: 320px;
            }
            button {
                background: var(--accent);
                border: none;
                color: #053322;
                font-weight: 700;
                padding: 12px 20px;
                border-radius: 999px;
                cursor: pointer;
            }
            .btn-secondary {
                background: transparent;
                border: 1px solid rgba(255,255,255,0.2);
                color: var(--ink);
            }
            .btn-danger {
                background: var(--danger);
                color: #2b0a0a;
            }
            .docs {
                display: grid;
                gap: 16px;
                margin-top: 16px;
            }
            .doc {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 16px;
                border-radius: 14px;
                background: rgba(255,255,255,0.04);
                border: 1px solid rgba(255,255,255,0.08);
            }
            .doc-actions {
                display: inline-flex;
                align-items: center;
                gap: 12px;
            }
            .doc a {
                color: var(--accent);
                text-decoration: none;
                font-weight: 600;
                padding: 8px 16px;
                background: rgba(77, 208, 168, 0.1);
                border-radius: 6px;
                display: inline-block;
            }
            .doc a:hover {
                background: rgba(77, 208, 168, 0.2);
            }
            .doc form {
                margin: 0;
            }
            .tag {
                font-size: 12px;
                color: var(--muted);
            }
            .flash {
                margin-top: 16px;
                color: var(--accent);
                font-weight: 600;
            }
            .error {
                margin-top: 12px;
                color: var(--danger);
                font-weight: 600;
            }
        </style>
    </head>
    <body>
        <div class="shell">
            <h1>Document Uploader</h1>
            <p>Upload a PDF, then jump into the editor to add text and save the updated file.</p>

            @if (session('status'))
                <div class="flash">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="error">{{ $errors->first() }}</div>
            @endif

            <div class="card">
                <form class="upload" action="{{ route('documents.store') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <input type="file" name="document" accept="application/pdf" required>
                    <button type="submit">Upload PDF</button>
                </form>
            </div>

            <div class="card">
                <h2 style="margin: 0 0 12px;">Your PDFs</h2>
                <div class="docs">
                    @forelse ($documents as $document)
                        <div class="doc">
                            <div>
                                <div>{{ $document->original_name }}</div>
                                <div class="tag">{{ number_format($document->size_bytes / 1024, 1) }} KB</div>
                            </div>
                            <div class="doc-actions">
                                <a href="{{ route('documents.edit', $document) }}">Edit</a>
                                <form action="{{ route('documents.destroy', $document) }}" method="POST">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn-danger" type="submit" onclick="return confirm('Delete this document?')">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <div class="tag">No uploads yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </body>
</html>
