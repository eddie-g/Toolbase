<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/netkit_logo_cube.svg') }}">
    <script>if(localStorage.getItem('darkMode')==='true')document.documentElement.classList.add('dark');</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Logo Studio - Netkit</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    {{-- The faces a generated vector logo's text may be set in: the generator's script renders them. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Abril+Fatface&family=Anton&family=Arvo:wght@400;700&family=Bebas+Neue&family=Bitter:wght@400;700&family=Bungee&family=Cabin:wght@400;700&family=Cinzel:wght@400;700&family=Comfortaa:wght@400;700&family=Cormorant+Garamond:wght@400;700&family=Dancing+Script:wght@400;700&family=DM+Sans:wght@400;700&family=Exo+2:wght@400;700&family=Fira+Sans:wght@400;700&family=IBM+Plex+Sans:wght@400;700&family=Inter:wght@400;700&family=Josefin+Sans:wght@400;700&family=Lato:wght@400;700&family=Libre+Baskerville:wght@400;700&family=Lobster&family=Macondo&family=Merriweather:wght@400;700&family=Montserrat:wght@400;700&family=Nunito:wght@400;700&family=Open+Sans:wght@400;700&family=Oswald:wght@400;700&family=Playfair+Display:wght@400;700&family=Poppins:wght@400;700&family=Raleway:wght@400;700&family=Roboto:wght@400;700&family=Rubik:wght@400;700&family=Source+Sans+3:wght@400;700&family=Space+Grotesk:wght@400;700&family=Work+Sans:wght@400;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .nk-lab {
            font-family: 'Inter', 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
            font-feature-settings: 'cv11', 'ss01';
            -webkit-font-smoothing: antialiased;
        }
        [x-cloak] { display: none !important; }
        .selection-box { vector-effect: non-scaling-stroke; }
        .style-sample-image { transition: transform 0.25s ease, filter 0.25s ease; }
        .group:hover .style-sample-image { transform: scale(1.06); filter: saturate(1.08); }
    </style>
</head>
<body class="nk-lab min-h-screen bg-zinc-50 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-50">
    {{-- The studio's own bar: no site navigation, the whole width for the generator --}}
    <header class="sticky top-0 z-40 border-b border-zinc-200 bg-white/90 backdrop-blur dark:border-zinc-800 dark:bg-zinc-950/90" data-studio-bar>
        <div class="flex h-14 items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
            <div class="flex min-w-0 items-center gap-3 sm:gap-4">
                <a href="{{ route('domainSearch.logoGenerator') }}" data-action="back-to-logos"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-md px-2 py-1.5 text-sm font-medium text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
                    Your logos
                </a>
                <span class="h-5 w-px bg-zinc-200 dark:bg-zinc-800" aria-hidden="true"></span>
                <div class="flex min-w-0 items-center gap-2.5">
                    <img src="{{ asset('images/netkit_logo_cube.svg') }}" alt="" class="h-7 w-7 object-contain">
                    <span class="truncate text-sm font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">Logo Studio</span>
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('domainSearch.logoGenerator', ['tab' => 'browse']) }}" class="hidden rounded-md px-2.5 py-1.5 text-sm font-medium text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100 sm:inline-flex">Browse logos</a>
                <button type="button" data-studio-theme-toggle aria-label="Toggle dark mode"
                    class="grid h-9 w-9 place-items-center rounded-md text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100">
                    <svg class="h-4.5 w-4.5 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z" /></svg>
                    <svg class="hidden h-4.5 w-4.5 dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4" /><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4m11.4-11.4 1.4-1.4" /></svg>
                </button>
            </div>
        </div>
    </header>

    <main class="px-4 py-6 sm:px-6 lg:px-8">
        @include('logos.partials.studio')
    </main>

    @include('logos.partials.generator-script')
    <script>
        document.querySelector('[data-studio-theme-toggle]')?.addEventListener('click', () => {
            const dark = document.documentElement.classList.toggle('dark');
            try { localStorage.setItem('darkMode', dark ? 'true' : 'false'); } catch (e) {}
        });
    </script>
</body>
</html>
