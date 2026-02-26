<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="h-full text-gray-200">

    <!-- Sidebar -->
    <div class="flex h-full">
        <aside class="w-56 flex-shrink-0 bg-gray-900 border-r border-gray-800 flex flex-col">
            <div class="px-5 py-5 border-b border-gray-800">
                <span class="text-xs font-semibold text-red-500 uppercase tracking-widest">Admin</span>
                <p class="text-sm font-bold text-white mt-0.5 truncate">{{ $admin->name }}</p>
                <p class="text-xs text-gray-500 truncate">{{ $admin->email }}</p>
            </div>

            <nav class="flex-1 px-3 py-4 space-y-0.5">
                <a href="{{ route('admin.dashboard') }}"
                   class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm font-medium bg-gray-800 text-white">
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"/></svg>
                    Dashboard
                </a>
                {{-- Add more admin nav links here as features grow --}}
            </nav>

            <div class="px-3 py-4 border-t border-gray-800">
                @if($admin->last_login_at)
                    <p class="text-xs text-gray-600 mb-3">
                        Last login: {{ $admin->last_login_at->diffForHumans() }}<br>
                        <span class="font-mono">{{ $admin->last_login_ip }}</span>
                    </p>
                @endif
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit"
                        class="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-gray-500 hover:text-red-400 hover:bg-gray-800 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        Sign out
                    </button>
                </form>
            </div>
        </aside>

        <!-- Main content -->
        <main class="flex-1 overflow-y-auto px-8 py-8">
            <h1 class="text-2xl font-bold text-white mb-6">Dashboard</h1>

            <!-- Stats -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
                <div class="rounded-xl bg-gray-900 border border-gray-800 p-5">
                    <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Total Users</p>
                    <p class="text-3xl font-bold text-white">{{ number_format($stats['users']) }}</p>
                </div>
                <div class="rounded-xl bg-gray-900 border border-gray-800 p-5">
                    <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Joined Today</p>
                    <p class="text-3xl font-bold text-white">{{ number_format($stats['users_today']) }}</p>
                </div>
                <div class="rounded-xl bg-gray-900 border border-gray-800 p-5">
                    <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Last 7 Days</p>
                    <p class="text-3xl font-bold text-white">{{ number_format($stats['users_7d']) }}</p>
                </div>
            </div>

            <p class="text-sm text-gray-600">
                Extend this dashboard by adding controllers and views under <code class="bg-gray-900 px-1.5 py-0.5 rounded text-xs">app/Http/Controllers/Admin/</code>
                and protecting them with the <code class="bg-gray-900 px-1.5 py-0.5 rounded text-xs">admin</code> middleware group.
            </p>
        </main>
    </div>

</body>
</html>
