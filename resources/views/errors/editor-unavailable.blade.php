<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/netkit_logo_cube.svg') }}">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <script>
        (() => {
            const saved = localStorage.getItem('darkMode');
            const useDark = saved === 'true' || (saved === null && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', useDark);
        })();
    </script>
    <title>The editor is temporarily unavailable - NETKIT</title>
    @vite(['resources/css/app.css'])
</head>
<body class="h-full bg-slate-50 text-slate-900 dark:bg-gray-950 dark:text-white">
    <main class="flex min-h-full items-center justify-center px-4 py-10">
        <div class="w-full max-w-md text-center" data-editor-unavailable>
            <a href="{{ route('home') }}" class="inline-flex items-center justify-center gap-3 text-2xl font-bold text-slate-950 dark:text-white">
                <img src="{{ asset('images/netkit_logo_cube.svg') }}" alt="NETKIT logo" class="h-14 w-14 object-contain">
                <span>NETKIT</span>
            </a>
            <h1 class="mt-8 text-xl font-semibold">The editor is temporarily unavailable</h1>
            <p class="mt-3 text-sm leading-6 text-slate-600 dark:text-gray-300">{{ $message }}</p>
            <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <a href="{{ route('documents.index') }}" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-white dark:text-slate-900 dark:hover:bg-gray-200">Your documents</a>
                <a href="{{ url()->current() }}" class="inline-flex items-center justify-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-900">Try again</a>
            </div>
        </div>
    </main>
</body>
</html>
