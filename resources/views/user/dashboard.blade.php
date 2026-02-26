<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-gray-950 text-gray-100">

    <x-site-header :showNavigation="true" :showAuthControls="true" />

    <main class="pt-24 pb-16 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto">

        {{-- Page header --}}
        <div class="mb-10">
            <h1 class="text-3xl font-bold text-white">Dashboard</h1>
            <p class="mt-1 text-sm text-gray-400">Manage your account and access your tools.</p>
        </div>

        {{-- Account card --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">

            {{-- Profile --}}
            <div class="md:col-span-2 bg-gray-900 rounded-2xl border border-gray-800 p-6 flex items-start gap-5">
                <div class="flex-shrink-0">
                    @if(Auth::user()->avatar)
                        <img src="{{ Auth::user()->avatar }}" class="h-16 w-16 rounded-full object-cover border-2 border-blue-500" alt="">
                    @else
                        <div class="h-16 w-16 rounded-full bg-blue-600/20 border-2 border-blue-500/40 flex items-center justify-center">
                            <svg class="h-8 w-8 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                        </div>
                    @endif
                </div>
                <div class="flex-1 min-w-0">
                    <h2 class="text-xl font-semibold text-white truncate">{{ Auth::user()->name }}</h2>
                    <p class="text-sm text-gray-400 mt-0.5">{{ Auth::user()->email }}</p>
                    @if(Auth::user()->email_verified_at)
                        <span class="inline-flex items-center gap-1 mt-2 text-xs text-emerald-400 bg-emerald-400/10 border border-emerald-400/20 rounded-full px-2.5 py-1">
                            <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Verified
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 mt-2 text-xs text-yellow-400 bg-yellow-400/10 border border-yellow-400/20 rounded-full px-2.5 py-1">
                            Email not verified
                        </span>
                    @endif
                    <div class="mt-4">
                        <form method="POST" action="{{ route('logout') }}" class="inline">
                            @csrf
                            <button type="submit" class="text-xs text-red-400 hover:text-red-300 transition-colors">
                                Sign out
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            {{-- Credits --}}
            <div class="bg-gray-900 rounded-2xl border border-gray-800 p-6 flex flex-col justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-gray-500 mb-1">Credit Balance</p>
                    <p class="text-4xl font-bold text-white">
                        {{ number_format(Auth::user()->credit_balance ?? 0, 2) }}
                    </p>
                    <p class="text-xs text-gray-500 mt-1">credits available</p>
                </div>
                <div class="mt-5">
                    <a href="{{ route('home') }}#pricing" class="inline-flex items-center gap-1.5 text-sm font-medium text-blue-400 hover:text-blue-300 transition-colors">
                        Add credits
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                </div>
            </div>
        </div>

        {{-- Subscriptions --}}
        @php
            $subscriptions = Auth::user()->subscriptions()->with('plan')->where('status', 'active')->get();
        @endphp

        @if($subscriptions->isNotEmpty())
        <section class="mb-10">
            <h2 class="text-lg font-semibold text-white mb-4">Active Subscriptions</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @foreach($subscriptions as $sub)
                <div class="bg-gray-900 rounded-xl border border-gray-800 p-5 flex items-center gap-4">
                    <div class="h-10 w-10 rounded-lg bg-blue-600/20 flex items-center justify-center flex-shrink-0">
                        <svg class="h-5 w-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-white truncate">{{ $sub->plan->name ?? 'Plan' }}</p>
                        <p class="text-xs text-gray-500">
                            Renews {{ $sub->renews_at ? \Carbon\Carbon::parse($sub->renews_at)->format('M j, Y') : '–' }}
                        </p>
                    </div>
                    <span class="ml-auto text-xs text-emerald-400 bg-emerald-400/10 border border-emerald-400/20 rounded-full px-2 py-0.5">Active</span>
                </div>
                @endforeach
            </div>
        </section>
        @endif

        {{-- Quick links --}}
        <section>
            <h2 class="text-lg font-semibold text-white mb-4">Tools</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

                <a href="{{ route('documents.index') }}" class="group bg-gray-900 hover:bg-gray-800 rounded-xl border border-gray-800 hover:border-blue-500/40 p-5 transition-all" style="text-decoration:none;">
                    <div class="h-10 w-10 rounded-lg bg-blue-600/20 flex items-center justify-center mb-4 group-hover:bg-blue-600/30 transition-colors">
                        <svg class="h-5 w-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </div>
                    <p class="text-sm font-semibold text-white">PDF Editor</p>
                    <p class="text-xs text-gray-500 mt-0.5">Edit, annotate and sign PDFs</p>
                </a>

                <a href="{{ route('domainSearch.index') }}" class="group bg-gray-900 hover:bg-gray-800 rounded-xl border border-gray-800 hover:border-purple-500/40 p-5 transition-all" style="text-decoration:none;">
                    <div class="h-10 w-10 rounded-lg bg-purple-600/20 flex items-center justify-center mb-4 group-hover:bg-purple-600/30 transition-colors">
                        <svg class="h-5 w-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/>
                        </svg>
                    </div>
                    <p class="text-sm font-semibold text-white">Domain Search</p>
                    <p class="text-xs text-gray-500 mt-0.5">Find and check domain availability</p>
                </a>

                <a href="/logo-generator" class="group bg-gray-900 hover:bg-gray-800 rounded-xl border border-gray-800 hover:border-emerald-500/40 p-5 transition-all" style="text-decoration:none;">
                    <div class="h-10 w-10 rounded-lg bg-emerald-600/20 flex items-center justify-center mb-4 group-hover:bg-emerald-600/30 transition-colors">
                        <svg class="h-5 w-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <p class="text-sm font-semibold text-white">Logo Generator</p>
                    <p class="text-xs text-gray-500 mt-0.5">Create logos with AI</p>
                </a>

            </div>
        </section>

    </main>

</body>
</html>
