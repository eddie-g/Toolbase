<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>PDF Viewer</title>
        <style>
            :root {
                color-scheme: light;
                --bg: #0b1320;
                --ink: #e9f0ff;
                --muted: #a9b7cf;
            }
            * { box-sizing: border-box; }
            html, body {
                margin: 0;
                height: 100%;
            }
            body {
                font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
                background: var(--bg);
                color: var(--ink);
                min-height: 100%;
            }
            .viewer {
                position: fixed;
                inset: 0;
                width: 100%;
                height: 100%;
                border: none;
                display: block;
                background: var(--bg);
            }
            .empty {
                height: 100%;
                display: grid;
                place-items: center;
                text-align: center;
                padding: 24px;
                color: var(--muted);
            }
        </style>
    </head>
    <body>
        @if ($document)
            <iframe class="viewer" src="{{ route('documents.file', $document) }}" title="Current PDF"></iframe>
        @else
            <div class="empty">
                <div>No PDF uploaded yet.</div>
            </div>
        @endif
    </body>
</html>
