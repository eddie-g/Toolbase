<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/netkit_logo_cube.svg') }}">
    <script>if(localStorage.getItem('darkMode')==='true')document.documentElement.classList.add('dark');</script>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>Domain Search FAQ - Netkit</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-950 antialiased min-h-screen">

    <!-- Header -->
    <x-site-header :compact="true" :show-navigation="false" :show-auth-controls="false" brand="NetKit" />

    <!-- Main Content -->
    <main class="pt-28 pb-20">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8 max-w-[1000px]">

            <!-- Title -->
            <div class="text-center mb-10">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-100 dark:bg-blue-900/40 rounded-2xl mb-5">
                    <svg class="w-8 h-8 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 dark:text-white mb-3">Frequently Asked Questions</h1>
                <p class="text-gray-500 dark:text-gray-400 text-lg">Learn more about our domain search service</p>
            </div>

            <!-- Back to Domain Search -->
            <div class="mb-8">
                <a href="/domain-search" class="inline-flex items-center gap-2 text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    Back to Domain Search
                </a>
            </div>

            <!-- FAQ Section -->
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-lg shadow-gray-200/50 dark:shadow-black/20 border border-gray-200 dark:border-gray-800 p-6 mb-8">
                <div class="space-y-6">
                    <!-- Privacy & Security FAQ Item -->
                    <div class="p-5 rounded-xl bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800/30">
                        <div class="flex items-start gap-3">
                            <svg class="w-6 h-6 text-green-600 dark:text-green-400 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                            <div class="flex-1">
                                <h3 class="text-lg font-bold text-green-900 dark:text-green-100 mb-3">🔒 Your Searches Are Private & Secure</h3>
                                <p class="text-base text-green-700 dark:text-green-300 leading-relaxed">
                                    Your domain searches are <strong>never logged or stored</strong> in our database. We perform direct WHOIS and availability checks without any middlemen. 
                                    Results are temporarily cached for 1 hour to improve performance, then automatically deleted.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Additional FAQ Items can be added here -->
                </div>
            </div>

        </div>
    </main>

</body>
</html>
