<!DOCTYPE html>
<html lang="en" x-data="{ 
    darkMode: localStorage.getItem('darkMode') === 'true' || false,
    toggleDarkMode() {
        this.darkMode = !this.darkMode;
        localStorage.setItem('darkMode', this.darkMode);
    }
}" x-init="$watch('darkMode', val => document.documentElement.classList.toggle('dark', val))" :class="{ 'dark': darkMode }">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Toolbase - Open-Source PDF Editor & Admin Dashboard</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-white dark:bg-gray-900 antialiased">
        <!-- Header -->
        <header class="fixed top-0 left-0 right-0 z-50 bg-white/80 dark:bg-gray-900/80 backdrop-blur-lg border-b border-gray-200 dark:border-gray-800">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-20">
                    <!-- Logo -->
                    <div class="flex items-center gap-3">
                        <svg class="h-10 w-10 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <span class="text-2xl font-bold text-gray-900 dark:text-white">Toolbase</span>
                        <span class="hidden sm:inline-block text-xs bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-400 px-2 py-1 rounded-full font-medium">v2.2</span>
                    </div>

                    <!-- Navigation -->
                    <nav class="hidden md:flex items-center gap-8">
                        <a href="#features" class="text-gray-600 dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-400 transition">Features</a>
                        <a href="#dashboards" class="text-gray-600 dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-400 transition">Dashboards</a>
                        <a href="#pdf-features" class="text-gray-600 dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-400 transition">PDF Editor</a>
                        <a href="#" class="text-gray-600 dark:text-gray-300 hover:text-blue-600 dark:hover:text-blue-400 transition">Docs</a>
                    </nav>

                    <!-- CTA Buttons -->
                    <div class="flex items-center gap-4">
                        <button @click="toggleDarkMode()" class="p-2 rounded-lg text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                            <svg x-show="!darkMode" class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                            </svg>
                            <svg x-show="darkMode" class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                            </svg>
                        </button>

                        @guest
                            <a href="{{ route('filament.admin.auth.login') }}" class="hidden sm:inline-flex items-center gap-2 px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                                Login
                            </a>
                        @endguest

                        @auth
                            <div class="relative ml-2" x-data="{ open: false }">
                                <button @click="open = !open" type="button" class="flex items-center justify-center w-10 h-10 rounded-full bg-gray-700 text-white hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-900 focus:ring-white transition" id="user-menu-button" aria-expanded="false" aria-haspopup="true">
                                    <span class="sr-only">Open user menu</span>
                                    @if(Auth::user()->avatar)
                                        <img class="h-9 w-9 rounded-full object-cover border-2 border-gray-600" src="{{ Auth::user()->avatar }}" alt="">
                                    @else
                                        <svg class="h-6 w-6 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                                    @endif
                                </button>

                                <div x-show="open" @click.away="open = false" x-transition:enter="transition ease-out duration-100" x-transition:enter-start="transform opacity-0 scale-95" x-transition:enter-end="transform opacity-100 scale-100" x-transition:leave="transition ease-in duration-75" x-transition:leave-start="transform opacity-100 scale-100" x-transition:leave-end="transform opacity-0 scale-95" class="absolute right-0 z-50 mt-2 w-48 origin-top-right rounded-xl bg-white dark:bg-[#1a2332] py-2 shadow-2xl ring-1 ring-black/5 dark:ring-white/10 focus:outline-none border border-gray-200 dark:border-gray-700/50 dark:backdrop-blur-xl" role="menu" aria-orientation="vertical" aria-labelledby="user-menu-button" tabindex="-1">
                                    <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700/50 mb-1">
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Signed in as</p>
                                        <p class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ Auth::user()->name }}</p>
                                    </div>
                                    <a href="/admin" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700/50 dark:hover:text-white transition-colors" role="menuitem" tabindex="-1">Dashboard</a>
                                    <a href="{{ route('filament.admin.pages.security') }}" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700/50 dark:hover:text-white transition-colors" role="menuitem" tabindex="-1">Security</a>
                                    <form method="POST" action="{{ route('filament.admin.auth.logout') }}">
                                        @csrf
                                        <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-gray-50 dark:hover:bg-gray-700/50 hover:text-red-700 dark:hover:text-red-300 transition-colors" role="menuitem" tabindex="-1">Sign out</button>
                                    </form>
                                </div>
                            </div>
                        @endauth
                    </div>

                </div>
            </div>
        </header>


        <!-- Hero Section -->
        <section class="pt-32 pb-20 px-4 sm:px-6 lg:px-8 bg-gradient-to-b from-white to-gray-50 dark:from-gray-900 dark:to-gray-800">
            <div class="container mx-auto">
                <div class="text-center max-w-4xl mx-auto mb-16">
                    <h1 class="text-5xl md:text-6xl lg:text-7xl font-bold text-gray-900 dark:text-white mb-6">
                        Open-Source <span class="text-blue-600">PDF Editor</span><br>& Admin Dashboard
                    </h1>
                    <p class="text-xl text-gray-600 dark:text-gray-300 mb-8 max-w-2xl mx-auto">
                        TailAdmin-powered PDF Editor with everything you need to create feature-rich backends, dashboards, and document management systems.
                    </p>
                    <div class="flex flex-col sm:flex-row items-center justify-center gap-4 mb-12">
                        <a href="/admin/login" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-4 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold text-lg transition shadow-lg shadow-blue-600/30">
                            Login
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                            </svg>
                        </a>
                        <a href="#pdf-features" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-4 border-2 border-gray-300 dark:border-gray-600 hover:border-blue-600 dark:hover:border-blue-500 text-gray-900 dark:text-white rounded-lg font-semibold text-lg transition">
                            Live Preview
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                            </svg>
                        </a>
                    </div>
                    <div class="flex items-center justify-center gap-4">
                        <div class="flex -space-x-2">
                            <img src="https://ui-avatars.com/api/?name=User+1&background=3b82f6&color=fff" alt="User 1" class="w-10 h-10 rounded-full border-2 border-white dark:border-gray-900">
                            <img src="https://ui-avatars.com/api/?name=User+2&background=10b981&color=fff" alt="User 2" class="w-10 h-10 rounded-full border-2 border-white dark:border-gray-900">
                            <img src="https://ui-avatars.com/api/?name=User+3&background=f59e0b&color=fff" alt="User 3" class="w-10 h-10 rounded-full border-2 border-white dark:border-gray-900">
                        </div>
                        <div class="text-left">
                            <div class="flex items-center gap-1 text-yellow-500 mb-1">
                                <svg class="w-4 h-4 fill-current" viewBox="0 0 20 20"><path d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z"/></svg>
                                <svg class="w-4 h-4 fill-current" viewBox="0 0 20 20"><path d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z"/></svg>
                                <svg class="w-4 h-4 fill-current" viewBox="0 0 20 20"><path d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z"/></svg>
                                <svg class="w-4 h-4 fill-current" viewBox="0 0 20 20"><path d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z"/></svg>
                                <svg class="w-4 h-4 fill-current" viewBox="0 0 20 20"><path d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z"/></svg>
                            </div>
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">80k+ Happy Users!</p>
                        </div>
                    </div>
                </div>

                <!-- Hero Image -->
                <div class="relative max-w-6xl mx-auto">
                    <div class="rounded-2xl border border-gray-200 dark:border-gray-700 shadow-2xl overflow-hidden bg-white dark:bg-gray-800">
                        <img src="https://tailadmin.com/_next/image?url=%2F_next%2Fstatic%2Fmedia%2Fmain-image.393386f9.png&w=1920&q=75" alt="Dashboard Preview" class="w-full">
                    </div>
                    <div class="absolute -bottom-6 left-10 right-10 h-12 bg-gradient-to-r from-blue-600 via-purple-600 to-pink-600 rounded-lg blur-3xl opacity-50"></div>
                </div>
            </div>
        </section>

        <!-- Trusted By Section -->
        <section id="pdf-features" class="py-16 px-4 sm:px-6 lg:px-8 bg-white dark:bg-gray-900">
            <div class="container mx-auto">
                <p class="text-center text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-8">
                    Trusted by over 80,000 individuals and companies worldwide
                </p>
                <div class="flex flex-wrap items-center justify-center gap-8 opacity-50 grayscale">
                    <div class="text-3xl font-bold text-gray-800 dark:text-gray-200">Alibaba</div>
                    <div class="text-3xl font-bold text-gray-800 dark:text-gray-200">NVIDIA</div>
                    <div class="text-3xl font-bold text-gray-800 dark:text-gray-200">Dolby</div>
                    <div class="text-3xl font-bold text-gray-800 dark:text-gray-200">Pexels</div>
                </div>
            </div>
        </section>

        <!-- PDF Features Section -->
        <section class="py-20 px-4 sm:px-6 lg:px-8 bg-gray-50 dark:bg-gray-800">
            <div class="container mx-auto">
                <div class="text-center max-w-3xl mx-auto mb-16">
                    <h2 class="text-4xl md:text-5xl font-bold text-gray-900 dark:text-white mb-4">
                        Powerful PDF Editing Features
                    </h2>
                    <p class="text-lg text-gray-600 dark:text-gray-300">
                        Everything you need to edit, annotate, and manage your PDF documents
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 max-w-7xl mx-auto">
                    <!-- Edit Feature -->
                    <div class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group">
                        <div class="w-14 h-14 bg-blue-100 dark:bg-blue-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Edit</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Directly edit text, images, and content within your PDF documents with precision and ease.
                        </p>
                    </div>

                    <!-- AI Generate Feature -->
                    <div class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group">
                        <div class="w-14 h-14 bg-purple-100 dark:bg-purple-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">AI Generate</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Leverage AI to automatically generate content, summaries, and intelligent document enhancements.
                        </p>
                    </div>

                    <!-- Merge Feature -->
                    <div class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group">
                        <div class="w-14 h-14 bg-green-100 dark:bg-green-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Merge</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Combine multiple PDF documents into a single file seamlessly with drag-and-drop simplicity.
                        </p>
                    </div>

                    <!-- Protect Feature -->
                    <div class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group">
                        <div class="w-14 h-14 bg-red-100 dark:bg-red-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Protect</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Secure your documents with password protection and encryption to keep sensitive data safe.
                        </p>
                    </div>

                    <!-- Draw Feature -->
                    <div class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group">
                        <div class="w-14 h-14 bg-yellow-100 dark:bg-yellow-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Draw</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Freehand drawing tools to sketch, highlight, and add custom visual elements to your PDFs.
                        </p>
                    </div>

                    <!-- Annotate Feature -->
                    <div class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group">
                        <div class="w-14 h-14 bg-indigo-100 dark:bg-indigo-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Annotate</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Add comments, notes, and markup to collaborate and provide feedback on PDF documents.
                        </p>
                    </div>

                    <!-- Sign Feature -->
                    <div class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group">
                        <div class="w-14 h-14 bg-pink-100 dark:bg-pink-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-pink-600 dark:text-pink-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Sign</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Add legally binding electronic signatures to documents with full compliance and security.
                        </p>
                    </div>

                    <!-- Get Started CTA Card -->
                    <div class="bg-gradient-to-br from-blue-600 to-purple-600 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group flex flex-col justify-center items-center text-center">
                        <h3 class="text-2xl font-bold text-white mb-3">Ready to Start?</h3>
                        <p class="text-blue-100 text-sm mb-6">
                            Access all features now
                        </p>
                        <a href="{{ route('documents.index') }}" class="inline-flex items-center gap-2 px-6 py-3 bg-white text-blue-600 rounded-lg font-semibold hover:bg-gray-100 transition">
                            Open Editor
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA Section -->
        <section class="py-20 px-4 sm:px-6 lg:px-8 bg-gradient-to-br from-blue-600 via-blue-700 to-purple-700 text-white">
            <div class="container mx-auto text-center">
                <h2 class="text-4xl md:text-5xl font-bold mb-6">
                    Join thousands using the #1<br>PDF Editor & Admin Dashboard!
                </h2>
                <p class="text-xl text-blue-100 mb-8 max-w-2xl mx-auto">
                    Start building amazing PDF editing experiences and admin panels today
                </p>
                <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="/admin/login" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-4 bg-white hover:bg-gray-100 text-blue-600 rounded-lg font-semibold text-lg transition shadow-xl">
                        Login
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                        </svg>
                    </a>
                    <a href="{{ route('documents.index') }}" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-4 border-2 border-white hover:bg-white/10 text-white rounded-lg font-semibold text-lg transition">
                        Live Preview
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                        </svg>
                    </a>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-12 px-4 sm:px-6 lg:px-8 bg-gray-900 text-gray-300">
            <div class="container mx-auto">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-8">
                    <div>
                        <div class="flex items-center gap-2 mb-4">
                            <svg class="h-8 w-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            <span class="text-xl font-bold text-white">Toolbase</span>
                        </div>
                        <p class="text-sm">Free and Open-Source PDF Editor & Admin Dashboard Template</p>
                    </div>
                    <div>
                        <h3 class="font-semibold text-white mb-4">Useful Links</h3>
                        <ul class="space-y-2 text-sm">
                            <li><a href="#" class="hover:text-white transition">Documentation</a></li>
                            <li><a href="#" class="hover:text-white transition">Blog</a></li>
                            <li><a href="#" class="hover:text-white transition">Update Logs</a></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="font-semibold text-white mb-4">About</h3>
                        <ul class="space-y-2 text-sm">
                            <li><a href="#" class="hover:text-white transition">Privacy Policy</a></li>
                            <li><a href="#" class="hover:text-white transition">License</a></li>
                            <li><a href="#" class="hover:text-white transition">Support</a></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="font-semibold text-white mb-4">Newsletter</h3>
                        <p class="text-sm mb-4">Subscribe for the latest updates</p>
                        <input type="email" placeholder="Enter your email" class="w-full px-4 py-2 rounded-lg bg-gray-800 border border-gray-700 focus:border-blue-500 focus:outline-none text-sm">
                    </div>
                </div>
                <div class="border-t border-gray-800 pt-8 text-center text-sm">
                    <p>&copy; 2026 Toolbase - All Rights Reserved.</p>
                </div>
            </div>
        </footer>
    </body>
</html>
