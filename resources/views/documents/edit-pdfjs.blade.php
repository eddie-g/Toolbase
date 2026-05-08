<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $document->original_name }} — Edit (PDF.js spike)</title>
    @vite(['resources/css/edit-pdfjs/index.css', 'resources/js/edit-pdfjs/main.js'])
</head>
<body>

<header class="epj-topbar">
    <h1>{{ $document->original_name }} — PDF.js spike</h1>
    <span id="epj-status">Loading…</span>
    <span class="epj-spacer"></span>
    <button id="epj-download" class="epj-primary" disabled>Download edited PDF</button>
</header>

<div id="epj-pages"
     data-doc-id="{{ $document->id }}"
     data-csrf="{{ csrf_token() }}"
     data-pdf-url="{{ route('documents.originalFile', $document) }}"
     data-rewrite-url="{{ route('documents.editPdfjsRewriteTj', $document) }}"></div>

<div id="epj-edit-bar">
    <span class="epj-orig" id="epj-orig">—</span>
    <input type="text" id="epj-input" placeholder="Edit text">
    <button id="epj-cancel">Cancel</button>
    <button id="epj-apply" class="epj-primary">Apply</button>
</div>

</body>
</html>
