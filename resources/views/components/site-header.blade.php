@props([
    'compact' => false,
    'showNavigation' => true,
    'showAuthControls' => true,
    'brand' => 'Netkit',
    'homeHref' => null,
])

@php
    $homeHref = $homeHref ?? route('home');
    $heightClass = $compact ? 'h-16' : 'h-20';
    $logoSizeClass = $compact ? 'h-12 w-12' : 'h-16 w-16';
    $brandSizeClass = $compact ? 'text-xl' : 'text-2xl';
    $iconSizeClass = $compact ? 'h-5 w-5' : 'h-6 w-6';
    $loginPaddingClass = $compact ? 'px-4 py-2' : 'px-6 py-2.5';
    $headerUser = auth()->user() ?? auth('admin')->user();
    $isAdmin = auth('admin')->check();
@endphp

<header
    x-data="{ mobileMenuOpen: false }"
    @keydown.escape.window="mobileMenuOpen = false"
    class="fixed top-0 left-0 right-0 z-[200] bg-white/80 dark:bg-gray-900/80 backdrop-blur-lg border-b border-gray-200 dark:border-gray-800"
>
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between {{ $heightClass }}">
            <a href="{{ $homeHref }}" class="flex items-center gap-3" style="text-decoration:none;">
                <img src="{{ asset('images/netkit_logo_black.svg') }}" alt="{{ $brand }} logo" class="{{ $logoSizeClass }} object-contain dark:hidden">
                <img src="{{ asset('images/netkit_logo_white.svg') }}" alt="{{ $brand }} logo" class="{{ $logoSizeClass }} object-contain hidden dark:block">
                <span id="header_logo" class="{{ $brandSizeClass }} font-bold text-gray-900 dark:text-white">{{ $brand }}</span>
            </a>

            @if ($showNavigation)
                <nav class="hidden md:flex items-center gap-8">
                    <a href="/pdf-editor" class="text-gray-600 dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-400 transition">PDF Editor</a>
                    <a href="/domain-search" class="text-gray-600 dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-400 transition">Domain Search</a>
                    <a href="/logo-generator" class="text-gray-600 dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-400 transition">Logo Generator</a>
                    <a href="/prices" class="text-gray-600 dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-400 transition">Prices</a>
                    <a href="/browse-logos" class="text-gray-600 dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-400 transition">Browse Logos</a>
                </nav>
            @endif

            <div class="flex items-center gap-2 sm:gap-4">
                <button data-theme-toggle class="p-2 rounded-lg text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition" type="button" style="background:transparent;">
                    <svg class="{{ $iconSizeClass }} block dark:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                    </svg>
                    <svg class="{{ $iconSizeClass }} hidden dark:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                    </svg>
                </button>

                @if ($showNavigation || $showAuthControls)
                    <button
                        type="button"
                        class="md:hidden p-2 rounded-lg text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
                        @click="mobileMenuOpen = !mobileMenuOpen"
                        :aria-expanded="mobileMenuOpen ? 'true' : 'false'"
                        aria-label="Toggle menu"
                    >
                        <svg x-show="!mobileMenuOpen" class="{{ $iconSizeClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                        <svg x-show="mobileMenuOpen" class="{{ $iconSizeClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                @endif

                @if ($showAuthControls)
                    @if(!$headerUser)
                        <a href="{{ route('login') }}" class="hidden md:inline-flex items-center gap-2 {{ $loginPaddingClass }} bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition" style="text-decoration:none;">
                            Login
                        </a>
                    @else
                        <div class="relative ml-2 hidden md:block" x-data="{ open: false }">
                            <button @click="open = !open" type="button" class="flex items-center justify-center w-10 h-10 rounded-full bg-gray-700 text-white hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-900 focus:ring-white transition" id="user-menu-button" aria-expanded="false" aria-haspopup="true" style="padding:0;">
                                <span class="sr-only">Open user menu</span>
                                @if($headerUser->avatar ?? null)
                                    <img class="h-9 w-9 rounded-full object-cover border-2 border-gray-600" src="{{ $headerUser->avatar }}" alt="">
                                @else
                                    <svg class="h-6 w-6 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                                @endif
                            </button>

                            <div x-show="open" @click.away="open = false" x-transition:enter="transition ease-out duration-100" x-transition:enter-start="transform opacity-0 scale-95" x-transition:enter-end="transform opacity-100 scale-100" x-transition:leave="transition ease-in duration-75" x-transition:leave-start="transform opacity-100 scale-100" x-transition:leave-end="transform opacity-0 scale-95" class="absolute right-0 z-[210] mt-2 w-48 origin-top-right rounded-xl bg-white dark:bg-[#1a2332] py-2 shadow-2xl ring-1 ring-black/5 dark:ring-white/10 focus:outline-none border border-gray-200 dark:border-gray-700/50 dark:backdrop-blur-xl" role="menu" aria-orientation="vertical" aria-labelledby="user-menu-button" tabindex="-1">
                                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700/50 mb-1">
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Signed in as</p>
                                    <p class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ $headerUser->name }}</p>
                                </div>
                                @if($isAdmin)
                                <a href="/admin" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700/50 dark:hover:text-white transition-colors" role="menuitem" tabindex="-1">Admin Dashboard</a>
                                <a href="{{ route('filament.admin.pages.security') }}" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700/50 dark:hover:text-white transition-colors" role="menuitem" tabindex="-1">Security</a>
                                @else
                                <a href="/portal" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700/50 dark:hover:text-white transition-colors" role="menuitem" tabindex="-1">My Dashboard</a>
                                @endif
                                <form method="POST" action="{{ $isAdmin ? route('filament.admin.auth.logout') : route('logout') }}">
                                    @csrf
                                    <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-gray-50 dark:hover:bg-gray-700/50 hover:text-red-700 dark:hover:text-red-300 transition-colors" role="menuitem" tabindex="-1" style="border-radius:0;">Sign out</button>
                                </form>
                            </div>
                        </div>
                    @endif
                @endif
            </div>
        </div>

        @if ($showNavigation || $showAuthControls)
            <div
                x-show="mobileMenuOpen"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 -translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 -translate-y-1"
                @click.away="mobileMenuOpen = false"
                class="md:hidden pb-4"
            >
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white/95 dark:bg-gray-900/95 backdrop-blur p-3 space-y-3">
                    @if ($showNavigation)
                        <nav class="flex flex-col">
                            <a @click="mobileMenuOpen = false" href="/pdf-editor" class="px-3 py-2 rounded-lg text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition">PDF Editor</a>
                            <a @click="mobileMenuOpen = false" href="/domain-search" class="px-3 py-2 rounded-lg text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition">Domain Search</a>
                            <a @click="mobileMenuOpen = false" href="/logo-generator" class="px-3 py-2 rounded-lg text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition">Logo Generator</a>
                            <a @click="mobileMenuOpen = false" href="/prices" class="px-3 py-2 rounded-lg text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition">Prices</a>
                            <a @click="mobileMenuOpen = false" href="/browse-logos" class="px-3 py-2 rounded-lg text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition">Browse Logos</a>
                        </nav>
                    @endif

                    @if ($showAuthControls)
                        <div class="border-t border-gray-200 dark:border-gray-700 pt-3">
                            @if(!$headerUser)
                                <a href="{{ route('login') }}" class="inline-flex w-full items-center justify-center gap-2 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition" style="text-decoration:none;">
                                    Login
                                </a>
                            @else
                                <div class="px-1 pb-2">
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Signed in as</p>
                                    <p class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ $headerUser->name }}</p>
                                </div>
                                @if($isAdmin)
                                <a @click="mobileMenuOpen = false" href="/admin" class="block px-3 py-2 rounded-lg text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition">Admin Dashboard</a>
                                <a @click="mobileMenuOpen = false" href="{{ route('filament.admin.pages.security') }}" class="block px-3 py-2 rounded-lg text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition">Security</a>
                                @else
                                <a @click="mobileMenuOpen = false" href="/portal" class="block px-3 py-2 rounded-lg text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition">My Dashboard</a>
                                @endif
                                <form method="POST" action="{{ $isAdmin ? route('filament.admin.auth.logout') : route('logout') }}" class="mt-1">
                                    @csrf
                                    <button type="submit" class="w-full text-left px-3 py-2 rounded-lg text-red-600 dark:text-red-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition">Sign out</button>
                                </form>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</header>

@once
    <script>
        (() => {
            if (window.__netkitThemeToggleBound) return;
            window.__netkitThemeToggleBound = true;

            const STORAGE_KEY = 'darkMode';

            const setTheme = (isDark) => {
                console.log(isDark);
                document.documentElement.classList.toggle('dark', isDark);
                localStorage.setItem(STORAGE_KEY, isDark ? 'true' : 'false');
            };

            window.netkitToggleTheme = () => {
                const isDark = !document.documentElement.classList.contains('dark');
                setTheme(isDark);
            };

            // Apply saved preference once on load.
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved === 'true' || saved === 'false') {
                setTheme(saved === 'true');
            }

            document.addEventListener('click', (event) => {
                const button = event.target.closest('[data-theme-toggle]');
                if (!button) return;
                const currentTheme = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                console.log(`Theme toggle clicked: ${currentTheme} → ${newTheme}`);
                window.netkitToggleTheme();
            });
        })();
    </script>
@endonce
