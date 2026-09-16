<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Netkit - Edit the PDF you already have</title>
    <meta name="description" content="Netkit is an open-source PDF editor and admin dashboard. Edit the text that is already in a PDF, keep its fonts and layout, and download exactly what you saw." />
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/netkit_logo_cube.svg') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .nk-home {
            font-family: 'Inter', 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
            font-feature-settings: 'cv11', 'ss01';
            -webkit-font-smoothing: antialiased;
        }
        .nk-grid {
            background-image: radial-gradient(circle at 1px 1px, rgba(24, 24, 27, 0.10) 1px, transparent 0);
            background-size: 22px 22px;
            mask-image: radial-gradient(ellipse 70% 60% at 50% 0%, #000 40%, transparent 100%);
            -webkit-mask-image: radial-gradient(ellipse 70% 60% at 50% 0%, #000 40%, transparent 100%);
        }
        .dark .nk-grid {
            background-image: radial-gradient(circle at 1px 1px, rgba(244, 244, 245, 0.10) 1px, transparent 0);
        }
        .nk-glow {
            background: radial-gradient(60% 55% at 50% 0%, rgba(37, 99, 235, 0.14), transparent 70%);
        }
        .dark .nk-glow {
            background: radial-gradient(60% 55% at 50% 0%, rgba(59, 130, 246, 0.18), transparent 70%);
        }
        .nk-window {
            box-shadow:
                0 1px 2px rgba(24, 24, 27, 0.06),
                0 24px 60px -24px rgba(24, 24, 27, 0.35);
        }
        .dark .nk-window {
            box-shadow:
                0 1px 2px rgba(0, 0, 0, 0.5),
                0 24px 70px -20px rgba(0, 0, 0, 0.8);
        }
        @keyframes nk-rise {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .nk-rise { animation: nk-rise 640ms cubic-bezier(0.2, 0.7, 0.2, 1) both; }
        .nk-rise-2 { animation-delay: 90ms; }
        .nk-rise-3 { animation-delay: 180ms; }
        .nk-rise-4 { animation-delay: 300ms; }
        @media (prefers-reduced-motion: reduce) {
            .nk-rise { animation: none; }
        }
        .nk-balance { text-wrap: balance; }
    </style>
</head>
<body class="nk-home bg-white text-zinc-900 dark:bg-zinc-950 dark:text-zinc-50">
    <x-site-header />

    {{-- Hero --}}
    <section class="relative overflow-hidden pt-36 pb-16 sm:pt-40 lg:pt-44">
        <div class="nk-grid absolute inset-0 pointer-events-none" aria-hidden="true"></div>
        <div class="nk-glow absolute inset-x-0 top-0 h-[42rem] pointer-events-none" aria-hidden="true"></div>

        <div class="relative mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-3xl text-center">
                <a href="#editor" class="nk-rise inline-flex items-center gap-2 rounded-full border border-zinc-200 bg-white/70 px-3 py-1 text-xs font-medium text-zinc-600 backdrop-blur hover:border-zinc-300 hover:text-zinc-900 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100 transition">
                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-blue-600 dark:bg-blue-400"></span>
                    Open source · Laravel, Filament and pdf.js
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                </a>

                <h1 class="nk-rise nk-rise-2 nk-balance mt-6 text-4xl font-semibold tracking-tight text-zinc-900 sm:text-5xl lg:text-6xl dark:text-white">
                    Edit the PDF you already have.
                </h1>

                <p class="nk-rise nk-rise-3 nk-balance mx-auto mt-5 max-w-2xl text-base leading-7 text-zinc-500 sm:text-lg dark:text-zinc-400">
                    Netkit opens the text that is already in a document and lets you change it in place. Fonts, rows and spacing stay where they were, and the file you download is the one you saw on screen.
                </p>

                <div class="nk-rise nk-rise-4 mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <a href="{{ route('documents.index') }}" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-zinc-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 sm:w-auto dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200 dark:focus-visible:outline-white transition">
                        Open the editor
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5-5 5M6 12h12" /></svg>
                    </a>
                    <a href="#story" class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-zinc-200 bg-white px-5 py-2.5 text-sm font-medium text-zinc-700 hover:border-zinc-300 hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 sm:w-auto dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:border-zinc-700 dark:hover:bg-zinc-800 transition">
                        How it works
                    </a>
                </div>
            </div>

            {{-- Product window --}}
            <div class="nk-rise nk-rise-4 relative mx-auto mt-14 max-w-5xl sm:mt-16">
                <div class="nk-window overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center gap-2 border-b border-zinc-200 bg-zinc-50 px-4 py-2.5 dark:border-zinc-800 dark:bg-zinc-900">
                        <span class="h-2.5 w-2.5 rounded-full bg-zinc-300 dark:bg-zinc-700"></span>
                        <span class="h-2.5 w-2.5 rounded-full bg-zinc-300 dark:bg-zinc-700"></span>
                        <span class="h-2.5 w-2.5 rounded-full bg-zinc-300 dark:bg-zinc-700"></span>
                        <span class="ml-3 hidden rounded-md border border-zinc-200 bg-white px-2.5 py-0.5 text-[11px] font-medium text-zinc-500 sm:inline-block dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">netkit / documents / newsletter.pdf</span>
                    </div>
                    <img
                        src="{{ asset('images/home_page_images/editor/editor-light-1x.webp') }}"
                        srcset="{{ asset('images/home_page_images/editor/editor-light-1x.webp') }} 1440w, {{ asset('images/home_page_images/editor/editor-light.webp') }} 2880w"
                        sizes="(min-width: 1024px) 1024px, 100vw"
                        width="1440" height="900"
                        alt="The Netkit editor with a newsletter open: a paragraph of the PDF's own text is selected in Edit mode, with the Text Options panel showing its font, size and style."
                        class="block w-full"
                        fetchpriority="high"
                    >
                </div>
                <p class="mt-4 text-center text-xs text-zinc-500 dark:text-zinc-500">A real capture: the document's own paragraph, selected and editable, with its embedded Lato face already picked in the panel.</p>
            </div>
        </div>
    </section>

    {{-- Story --}}
    <section id="story" class="border-t border-zinc-200 py-20 sm:py-24 dark:border-zinc-800">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-12 lg:gap-16">
                <div class="lg:col-span-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-600 dark:text-blue-400">The story</p>
                    <h2 class="nk-balance mt-3 text-3xl font-semibold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">Most editors make you rebuild the page. Netkit edits it.</h2>
                    <div class="mt-6 space-y-4 text-base leading-7 text-zinc-600 dark:text-zinc-400">
                        <p>A PDF is a drawing of text, not a text document. Every tool that promises to "edit" one has to decide what to do with that: most cover the old words with a white box and type new ones on top, in whatever font happens to be installed.</p>
                        <p>Netkit started as the PDF editor inside an open-source Laravel admin dashboard, and it took the harder road. When a document opens, its text is promoted into editable boxes that keep the original rows, the embedded fonts and the exact position on the page. Change a word and only that word changes.</p>
                        <p>The download is held to the same standard. Every edit path is checked by an automated suite that reads the exported PDF back with PyMuPDF and compares it, character by character and point by point, with what the editor showed.</p>
                    </div>
                </div>

                <div class="lg:col-span-7">
                    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900">
                        <img
                            src="{{ asset('images/home_page_images/editor/detail-light.webp') }}"
                            width="1110" height="760"
                            alt="Close-up of a paragraph in Edit mode: two words selected inside the PDF's own text, with the floating move, lock and edit controls above the box."
                            class="block w-full"
                            loading="lazy"
                        >
                    </div>
                    <dl class="mt-6 grid grid-cols-1 gap-x-8 gap-y-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-semibold text-zinc-900 dark:text-white">Text becomes boxes</dt>
                            <dd class="mt-1 text-sm leading-6 text-zinc-500 dark:text-zinc-400">Each paragraph, title and row of the source is promoted into an editable block on its glyph bounds.</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-semibold text-zinc-900 dark:text-white">Fonts are the document's own</dt>
                            <dd class="mt-1 text-sm leading-6 text-zinc-500 dark:text-zinc-400">Embedded faces are extracted and reused; a bundled substitute steps in only when a glyph is missing.</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-semibold text-zinc-900 dark:text-white">Rows stay rows</dt>
                            <dd class="mt-1 text-sm leading-6 text-zinc-500 dark:text-zinc-400">A one-word edit keeps every other line where it was. Resize the box and the paragraph reflows the way the editor shows.</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-semibold text-zinc-900 dark:text-white">The download is checked</dt>
                            <dd class="mt-1 text-sm leading-6 text-zinc-500 dark:text-zinc-400">Moved text is scrubbed from where it was, untouched pages stay byte-identical, and the export matches the screen.</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </section>

    {{-- Editor showcase --}}
    <section id="editor" class="border-t border-zinc-200 bg-zinc-50 py-20 sm:py-24 dark:border-zinc-800 dark:bg-zinc-900/40">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            <div class="max-w-2xl">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-600 dark:text-blue-400">The editor</p>
                <h2 class="nk-balance mt-3 text-3xl font-semibold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">Every tool on the toolbar, in one page.</h2>
                <p class="mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-400">The same tools you see across the top of the editor. Pick one, work on the page, download.</p>
            </div>

            @php
                $editorTools = [
                    ['name' => 'Edit PDF', 'text' => 'Select any existing text and change it in place. Bold, italic, colour and font apply to a whole block or a selection.', 'icon' => 'M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10'],
                    ['name' => 'Text', 'text' => 'Add new text boxes anywhere on the page with your own font, size and alignment.', 'icon' => 'M3.75 5.25h16.5M12 5.25v13.5M8.25 18.75h7.5'],
                    ['name' => 'Sign', 'text' => 'Draw, type or upload a signature and place it on the document.', 'icon' => 'M3 17.25c2-3 4-4.5 6-4.5s3 3 5 3 3.5-6 6-6M3 20.25h18'],
                    ['name' => 'Shapes', 'text' => 'Rectangles, ellipses, lines and arrows with fill, stroke and rotation.', 'icon' => 'M4.5 4.5h7.5v7.5H4.5zM15 15a4.5 4.5 0 109 0 4.5 4.5 0 00-9 0z'],
                    ['name' => 'Draw', 'text' => 'Freehand ink with adjustable smoothing, width and colour.', 'icon' => 'M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42'],
                    ['name' => 'Highlight', 'text' => 'Mark up passages of the document\'s own text; the highlight follows the words.', 'icon' => 'M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z'],
                    ['name' => 'Image', 'text' => 'Place, resize and rotate images; a locked image sits under your annotations.', 'icon' => 'M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A1.5 1.5 0 0021.75 19.5V4.5A1.5 1.5 0 0020.25 3H3.75A1.5 1.5 0 002.25 4.5v15A1.5 1.5 0 003.75 21z'],
                    ['name' => 'Fields', 'text' => 'Fill form fields, and keep every widget intact when text around them is edited.', 'icon' => 'M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z'],
                    ['name' => 'Convert', 'text' => 'Export to Word, Excel, images or PDF/A, with the advanced options behind one toggle.', 'icon' => 'M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5'],
                    ['name' => 'Organize', 'text' => 'Merge files, split pages, reorder and rotate, up to the page limit the tool shows you.', 'icon' => 'M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z'],
                    ['name' => 'Password', 'text' => 'Protect a document, or unlock one you have the password for, without leaving the editor.', 'icon' => 'M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z'],
                ];
            @endphp

            <div class="mt-12 grid grid-cols-1 gap-8 lg:grid-cols-12">
                <div class="grid grid-cols-1 gap-px overflow-hidden rounded-xl border border-zinc-200 bg-zinc-200 sm:grid-cols-2 lg:col-span-8 lg:grid-cols-3 dark:border-zinc-800 dark:bg-zinc-800">
                    @foreach ($editorTools as $tool)
                        <div class="bg-white p-5 dark:bg-zinc-950">
                            <div class="flex items-center gap-2.5">
                                <span class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-zinc-200 bg-zinc-50 text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $tool['icon'] }}" /></svg>
                                </span>
                                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $tool['name'] }}</h3>
                            </div>
                            <p class="mt-3 text-sm leading-6 text-zinc-500 dark:text-zinc-400">{{ $tool['text'] }}</p>
                        </div>
                    @endforeach
                    <a href="{{ route('documents.index') }}" class="group flex flex-col justify-between bg-zinc-900 p-5 text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200 transition">
                        <h3 class="text-sm font-semibold">Try it on your own file</h3>
                        <p class="mt-3 text-sm leading-6 text-zinc-300 dark:text-zinc-600">Upload a PDF and open it in the editor. Editing existing text is a premium feature; everything else is free to try.</p>
                        <span class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium">
                            Open the editor
                            <svg class="h-4 w-4 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5-5 5M6 12h12" /></svg>
                        </span>
                    </a>
                </div>

                <figure class="lg:col-span-4">
                    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
                        <img
                            src="{{ asset('images/home_page_images/editor/panel-light.webp') }}"
                            width="790" height="1570"
                            alt="The Text Options panel of the editor: font picker set to the document's embedded Lato, a size slider at 12pt, text and background colours, opacity, bold, italic, underline and strikethrough, alignment, text tools and a hyperlink field."
                            class="block w-full"
                            loading="lazy"
                        >
                    </div>
                    <figcaption class="mt-3 text-xs leading-5 text-zinc-500 dark:text-zinc-500">The Text Options panel reads the selected text's real font. Document faces are listed first, then the bundled families.</figcaption>
                </figure>
            </div>
        </div>
    </section>

    {{-- Beyond the editor --}}
    <section id="tools" class="border-t border-zinc-200 py-20 sm:py-24 dark:border-zinc-800">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            <div class="max-w-2xl">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-600 dark:text-blue-400">Also in the kit</p>
                <h2 class="nk-balance mt-3 text-3xl font-semibold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">Two more tools and the dashboard that runs them.</h2>
            </div>

            <div class="mt-12 grid grid-cols-1 gap-6 md:grid-cols-3">
                <a href="{{ route('domainSearch.index') }}" class="group rounded-xl border border-zinc-200 bg-white p-6 hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-950 dark:hover:border-zinc-700 transition">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-zinc-200 bg-zinc-50 text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">
                        <svg class="h-4.5 w-4.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3 7.5 7.03 7.5 12s2.015 9 4.5 9zM3.6 9h16.8M3.6 15h16.8" /></svg>
                    </span>
                    <h3 class="mt-4 text-base font-semibold text-zinc-900 dark:text-white">Domain Search</h3>
                    <p class="mt-2 text-sm leading-6 text-zinc-500 dark:text-zinc-400">Check whether a name is taken and get alternatives generated around it, with the answers to the usual questions on one page.</p>
                    <span class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-zinc-900 dark:text-white">Search a domain <svg class="h-4 w-4 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5-5 5M6 12h12" /></svg></span>
                </a>

                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-zinc-200 bg-zinc-50 text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">
                        <svg class="h-4.5 w-4.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z" /></svg>
                    </span>
                    <h3 class="mt-4 text-base font-semibold text-zinc-900 dark:text-white">Logo Generator</h3>
                    <p class="mt-2 text-sm leading-6 text-zinc-500 dark:text-zinc-400">Vector and raster directions from a short brief. Open any result in the editor.</p>
                    <div class="mt-4 grid grid-cols-4 gap-2">
                        <img src="{{ asset('images/home_page_images/vector/vector_lion.svg') }}" alt="Vector lion logo" class="aspect-square w-full rounded-md border border-zinc-200 object-cover dark:border-zinc-800" loading="lazy">
                        <img src="{{ asset('images/home_page_images/vector/vector_sun_abstract.svg') }}" alt="Vector abstract sun logo" class="aspect-square w-full rounded-md border border-zinc-200 object-cover dark:border-zinc-800" loading="lazy">
                        <img src="{{ asset('images/home_page_images/image/raster_dragon_photorealistic.webp') }}" alt="Raster dragon logo" class="aspect-square w-full rounded-md border border-zinc-200 object-cover dark:border-zinc-800" loading="lazy">
                        <img src="{{ asset('images/home_page_images/image/raster_icegiant_fantasy.png') }}" alt="Raster ice giant logo" class="aspect-square w-full rounded-md border border-zinc-200 object-cover dark:border-zinc-800" loading="lazy">
                    </div>
                    <div class="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-sm font-medium">
                        <a href="/logo-generator" class="text-zinc-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400 transition">Generate a logo</a>
                        <a href="{{ route('browse-logos') }}" class="text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition">Browse the gallery</a>
                    </div>
                </div>

                <a href="/admin/login" class="group rounded-xl border border-zinc-200 bg-white p-6 hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-950 dark:hover:border-zinc-700 transition">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-zinc-200 bg-zinc-50 text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">
                        <svg class="h-4.5 w-4.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h12A2.25 2.25 0 0120.25 6v12A2.25 2.25 0 0118 20.25H6A2.25 2.25 0 013.75 18V6zM3.75 9.75h16.5M9.75 9.75v10.5" /></svg>
                    </span>
                    <h3 class="mt-4 text-base font-semibold text-zinc-900 dark:text-white">Admin dashboard</h3>
                    <p class="mt-2 text-sm leading-6 text-zinc-500 dark:text-zinc-400">Documents, users, credits, security settings and the automated test catalogue, on a Filament panel you can extend.</p>
                    <span class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-zinc-900 dark:text-white">Sign in to the dashboard <svg class="h-4 w-4 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5-5 5M6 12h12" /></svg></span>
                </a>
            </div>
        </div>
    </section>

    {{-- FAQ --}}
    <section id="faq" class="border-t border-zinc-200 bg-zinc-50 py-20 sm:py-24 dark:border-zinc-800 dark:bg-zinc-900/40">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 gap-10 lg:grid-cols-12">
                <div class="lg:col-span-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-600 dark:text-blue-400">Questions</p>
                    <h2 class="nk-balance mt-3 text-3xl font-semibold tracking-tight text-zinc-900 dark:text-white">Before you upload</h2>
                </div>
                @php
                    $faqs = [
                        ['q' => 'Does editing existing text really keep the original font?', 'a' => 'Yes, when the font is embedded in the PDF, which is nearly always. Netkit extracts the embedded faces and draws your edit with them. If a glyph you type is not in the embedded subset, a bundled family that matches the original as closely as possible steps in for that glyph.'],
                        ['q' => 'What happens to the rest of the page when I change a word?', 'a' => 'Nothing. The block you edited is redrawn in place; every other block, image and form field on the page is left untouched, and pages you did not edit are byte-identical to the original.'],
                        ['q' => 'Which parts are free?', 'a' => 'Uploading, viewing, adding text, shapes, drawings, highlights, images and signatures, converting, organizing and password tools are free to use. Editing the text that is already in a document is a premium feature.'],
                        ['q' => 'Can I run Netkit myself?', 'a' => 'Netkit is an open-source Laravel application with a Filament admin panel. The editor is built on pdf.js in the browser and PyMuPDF on the server, so it runs anywhere PHP and Python do.'],
                    ];
                @endphp
                <div class="lg:col-span-8">
                    <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-950">
                        @foreach ($faqs as $index => $faq)
                            <details class="group px-5 py-4" @if ($index === 0) open @endif>
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-sm font-semibold text-zinc-900 marker:content-none dark:text-white">
                                    {{ $faq['q'] }}
                                    <svg class="h-4 w-4 shrink-0 text-zinc-400 transition-transform group-open:rotate-45" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" /></svg>
                                </summary>
                                <p class="mt-3 max-w-2xl text-sm leading-6 text-zinc-500 dark:text-zinc-400">{{ $faq['a'] }}</p>
                            </details>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Closing call to action --}}
    <section class="border-t border-zinc-200 py-20 sm:py-24 dark:border-zinc-800">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            <div class="relative overflow-hidden rounded-2xl border border-zinc-200 bg-zinc-900 px-6 py-14 text-center sm:px-12 dark:border-zinc-800 dark:bg-white">
                <div class="pointer-events-none absolute inset-0" style="background: radial-gradient(50% 80% at 50% 100%, rgba(37, 99, 235, 0.35), transparent 70%);" aria-hidden="true"></div>
                <h2 class="nk-balance relative text-3xl font-semibold tracking-tight text-white sm:text-4xl dark:text-zinc-900">Open a PDF. Change what needs changing. Download.</h2>
                <p class="relative mx-auto mt-4 max-w-xl text-base leading-7 text-zinc-300 dark:text-zinc-600">No account is needed to try the editor. Sign in to keep your documents and to unlock editing of existing text.</p>
                <div class="relative mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <a href="{{ route('documents.index') }}" class="inline-flex w-full items-center justify-center rounded-lg bg-white px-5 py-2.5 text-sm font-medium text-zinc-900 hover:bg-zinc-200 sm:w-auto dark:bg-zinc-900 dark:text-white dark:hover:bg-zinc-800 transition">Open the editor</a>
                    <a href="{{ route('login') }}" class="inline-flex w-full items-center justify-center rounded-lg border border-white/20 px-5 py-2.5 text-sm font-medium text-white hover:bg-white/10 sm:w-auto dark:border-zinc-300 dark:text-zinc-900 dark:hover:bg-zinc-100 transition">Sign in</a>
                </div>
            </div>
        </div>
    </section>

    {{-- Footer --}}
    <footer class="border-t border-zinc-200 py-10 dark:border-zinc-800">
        <div class="mx-auto flex max-w-6xl flex-col gap-8 px-4 sm:px-6 md:flex-row md:items-start md:justify-between lg:px-8">
            <div class="max-w-xs">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-2.5">
                    <img src="{{ asset('images/netkit_logo_cube.svg') }}" alt="" class="h-7 w-7">
                    <span class="text-base font-semibold text-zinc-900 dark:text-white">Netkit</span>
                </a>
                <p class="mt-3 text-sm leading-6 text-zinc-500 dark:text-zinc-400">An open-source PDF editor and admin dashboard, built on Laravel, Filament, pdf.js and PyMuPDF.</p>
            </div>
            <nav class="grid grid-cols-2 gap-x-12 gap-y-2 text-sm sm:grid-cols-3" aria-label="Footer">
                <a href="{{ route('documents.index') }}" class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition">PDF Editor</a>
                <a href="{{ route('domainSearch.index') }}" class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition">Domain Search</a>
                <a href="/logo-generator" class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition">Logo Generator</a>
                <a href="{{ route('browse-logos') }}" class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition">Browse Logos</a>
                <a href="#faq" class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition">Questions</a>
                <a href="{{ route('login') }}" class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition">Sign in</a>
            </nav>
        </div>
        <div class="mx-auto mt-8 max-w-6xl px-4 text-xs text-zinc-500 sm:px-6 lg:px-8 dark:text-zinc-500">&copy; {{ date('Y') }} Netkit. All rights reserved.</div>
    </footer>
</body>
</html>
