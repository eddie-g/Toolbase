<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $document->original_name }} — Edit New</title>
    <script type="module">
        // PDF.js v4 ships ESM-only — no UMD global. Load the legacy ESM build
        // (broader browser support) and expose `pdfjsLib` as a window global so
        // existing edit-new code (and other blade pages still on v3) keep
        // working without an importmap. Module scripts execute in source order,
        // so this runs before the @vite app bundle that consumes window.pdfjsLib.
        import * as pdfjsLib from 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/legacy/build/pdf.min.mjs';
        pdfjsLib.GlobalWorkerOptions.workerSrc =
            'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/legacy/build/pdf.worker.min.mjs';
        window.pdfjsLib = pdfjsLib;
        window.dispatchEvent(new Event('pdfjsLibReady'));
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <!-- Popular Google Fonts available in the /edit-new font picker. Loaded
         once at page load so the canvas + contenteditable can render any of
         them immediately when the user picks one in the format bar. The
         server-side PDF export reads the same TTFs from python/pdf-editor/fonts. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow:ital,wght@0,400;0,700;1,400;1,700&family=Bebas+Neue&family=Cabin:ital,wght@0,400;0,700;1,400;1,700&family=Crimson+Text:ital,wght@0,400;0,700;1,400;1,700&family=DM+Sans:ital,wght@0,400;0,700;1,400;1,700&family=Dosis:wght@400;700&family=Fira+Sans:ital,wght@0,400;0,700;1,400;1,700&family=Heebo:wght@400;700&family=Hind:wght@400;700&family=Inter:ital,wght@0,400;0,700;1,400;1,700&family=Josefin+Sans:ital,wght@0,400;0,700;1,400;1,700&family=Kanit:ital,wght@0,400;0,700;1,400;1,700&family=Karla:ital,wght@0,400;0,700;1,400;1,700&family=Lato:ital,wght@0,400;0,700;1,400;1,700&family=Lora:ital,wght@0,400;0,700;1,400;1,700&family=Manrope:wght@400;700&family=Merriweather:ital,wght@0,400;0,700;1,400;1,700&family=Montserrat:ital,wght@0,400;0,700;1,400;1,700&family=Mukta:wght@400;700&family=Mulish:ital,wght@0,400;0,700;1,400;1,700&family=Noto+Sans:ital,wght@0,400;0,700;1,400;1,700&family=Noto+Serif:ital,wght@0,400;0,700;1,400;1,700&family=Nunito:ital,wght@0,400;0,700;1,400;1,700&family=Open+Sans:ital,wght@0,400;0,700;1,400;1,700&family=Oswald:wght@400;700&family=PT+Sans:ital,wght@0,400;0,700;1,400;1,700&family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Poppins:ital,wght@0,400;0,700;1,400;1,700&family=Quicksand:wght@400;700&family=Raleway:ital,wght@0,400;0,700;1,400;1,700&family=Roboto+Condensed:ital,wght@0,400;0,700;1,400;1,700&family=Roboto+Mono:ital,wght@0,400;0,700;1,400;1,700&family=Roboto+Slab:wght@400;700&family=Roboto:ital,wght@0,400;0,700;1,400;1,700&family=Rubik:ital,wght@0,400;0,700;1,400;1,700&family=Source+Sans+3:ital,wght@0,400;0,700;1,400;1,700&family=Ubuntu:ital,wght@0,400;0,700;1,400;1,700&family=Work+Sans:ital,wght@0,400;0,700;1,400;1,700&display=swap">
    @vite(['resources/css/edit-new/index.css'])
</head>
<body>

{{-- Server-side config consumed by resources/js/edit-new/main.js. --}}
<div id="edit-new-root"
     data-doc-id="{{ $document->id }}"
     data-csrf="{{ csrf_token() }}"
     data-info-url="{{ route('pdfTests.documentInfo', $document) }}"
     data-save-url="{{ route('documents.saveAnnotationState', $document) }}"
     data-download-url="{{ route('documents.downloadAnnotatedPdf', $document) }}"></div>

@include('documents.edit-new._topbar')

@include('documents.edit-new._pages')

@include('documents.edit-new._floating-toolbar')
@include('documents.edit-new._shape-panel')
@include('documents.edit-new._draw-panel')
@include('documents.edit-new._markup-modal')
@include('documents.edit-new._signature-modal')
@include('documents.edit-new._image-modal')
@include('documents.edit-new._zoom-bar')

@include('documents.edit-new._format-bar')

@vite(['resources/js/edit-new/main.js'])

</body>
</html>
