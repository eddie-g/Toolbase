<!-- Top Navigation Bar -->
<nav class="bg-gray-900/95 border-b border-gray-700/50 backdrop-blur-sm sticky top-0 z-[60]">
    <div class="px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-14">
            <!-- Logo -->
            <a href="/" class="flex items-center gap-3">
                <img src="{{ asset('images/netkit_logo_cube.svg') }}" alt="Netkit logo" class="h-8 w-8 object-contain">
                <span class="text-xl font-bold text-white">Netkit</span>
            </a>

            <!-- Right Side: Theme Toggle & Login -->
            <div class="flex items-center gap-3">
                <button id="theme-toggle" type="button" class="p-2 rounded-lg text-gray-300 hover:bg-gray-700/50 transition" title="Toggle theme">
                    <svg id="theme-icon-dark" class="h-5 w-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                    </svg>
                    <svg id="theme-icon-light" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                    </svg>
                </button>

                @if(!$editorIsAuthenticated)
                    <a href="{{ route('login') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition">
                        Login
                    </a>
                @endif

                @if($editorIsAuthenticated && $editorUser)
                    <div class="relative ml-2">
                        <button type="button" class="flex items-center justify-center w-10 h-10 rounded-full bg-gray-700 text-white hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-900 focus:ring-white transition" id="user-menu-button" aria-expanded="false" aria-haspopup="true" onclick="const menu = document.getElementById('user-menu'); menu.classList.toggle('hidden');">
                            <span class="sr-only">Open user menu</span>
                            @if(!empty($editorUser->avatar ?? null))
                                <img class="h-9 w-9 rounded-full object-cover border-2 border-gray-600" src="{{ $editorUser->avatar }}" alt="">
                            @else
                                <svg class="h-6 w-6 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                            @endif
                        </button>

                        <div class="hidden absolute right-0 z-50 mt-2 w-48 origin-top-right rounded-xl bg-[#1a2332] py-2 shadow-2xl ring-1 ring-white/10 focus:outline-none border border-gray-700/50 backdrop-blur-xl" role="menu" aria-orientation="vertical" aria-labelledby="user-menu-button" tabindex="-1" id="user-menu">
                            <div class="px-4 py-3 border-b border-gray-700/50 mb-1">
                                <p class="text-xs text-gray-400">Signed in as</p>
                                <p class="text-sm font-medium text-white truncate">{{ $editorUser->name ?? $editorUser->email ?? 'User' }}</p>
                            </div>
                            @if($editorIsAdmin)
                            <a href="/admin" class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700/50 hover:text-white transition-colors" role="menuitem" tabindex="-1">Admin Dashboard</a>
                            <a href="{{ route('filament.admin.pages.security') }}" class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700/50 hover:text-white transition-colors" role="menuitem" tabindex="-1">Security</a>
                            @endif
                            <a href="/portal" class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700/50 hover:text-white transition-colors" role="menuitem" tabindex="-1">My Dashboard</a>
                            <form method="POST" action="{{ $editorIsAdmin ? route('filament.admin.auth.logout') : route('logout') }}">
                                @csrf
                                <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-red-400 hover:bg-gray-700/50 hover:text-red-300 transition-colors" role="menuitem" tabindex="-1">Sign out</button>
                            </form>
                        </div>
                    </div>
                    <script>
                        document.addEventListener('click', function(event) {
                            const menu = document.getElementById('user-menu');
                            const button = document.getElementById('user-menu-button');
                            if (menu && button && !button.contains(event.target) && !menu.contains(event.target)) {
                                menu.classList.add('hidden');
                            }
                        });
                    </script>
                @endif
            </div>
        </div>
    </div>
</nav>

<!-- Mobile Header -->
<header class="bg-gray-800/95 border-b border-gray-700/50 backdrop-blur-sm sticky top-14 z-50">
    <div class="px-4 py-2">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3 flex-1 min-w-0">
                <button id="mobile-menu-toggle" class="lg:hidden p-2 hover:bg-gray-700/50 rounded-lg">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>
                <div class="flex-1 min-w-0">
                    <div id="pdf_title" class="text-sm font-semibold truncate">{{ $document->original_name }}</div>
                    <div class="flex items-center gap-2 mt-2">
                        <a href="{{ route('documents.index') }}" class="inline-flex items-center gap-2 px-6 py-2 bg-orange-500 hover:bg-orange-600 rounded-lg text-sm font-medium text-white transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                            </svg>
                            Return
                        </a>
                        <button id="overlay-undo" type="button" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg text-sm font-medium text-white disabled:opacity-50 disabled:cursor-not-allowed transition" title="Undo (Ctrl+Z)" disabled>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path>
                            </svg>
                            <span class="hidden sm:inline">Undo</span>
                        </button>
                        <button id="overlay-redo" type="button" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg text-sm font-medium text-white disabled:opacity-50 disabled:cursor-not-allowed transition" title="Redo (Ctrl+Y)" disabled>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 10h-10a8 8 0 00-8 8v2M21 10l-6 6m6-6l-6-6"></path>
                            </svg>
                            <span class="hidden sm:inline">Redo</span>
                        </button>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2">
            </div>
        </div>
    </div>
</header>
