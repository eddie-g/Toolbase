<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="h-full flex items-center justify-center">

    <div class="w-full max-w-sm px-6">
        <div class="mb-8 text-center">
            <span class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-red-600/20 border border-red-500/30 mb-4">
                <svg class="w-6 h-6 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
            </span>
            <h1 class="text-xl font-bold text-white">Admin Access</h1>
            <p class="text-sm text-gray-500 mt-1">{{ config('app.name') }} Control Panel</p>
        </div>

        <form method="POST" action="{{ route('admin.login.submit') }}" class="space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-xs font-medium text-gray-400 mb-1.5">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                    class="w-full rounded-lg bg-gray-900 border border-gray-700 text-white px-3.5 py-2.5 text-sm placeholder-gray-600
                           focus:outline-none focus:ring-2 focus:ring-red-500/50 focus:border-red-500
                           @error('email') border-red-500 @enderror">
                @error('email')
                    <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="block text-xs font-medium text-gray-400 mb-1.5">Password</label>
                <input id="password" type="password" name="password" required
                    class="w-full rounded-lg bg-gray-900 border border-gray-700 text-white px-3.5 py-2.5 text-sm
                           focus:outline-none focus:ring-2 focus:ring-red-500/50 focus:border-red-500">
            </div>

            <div class="flex items-center gap-2">
                <input id="remember" type="checkbox" name="remember"
                    class="rounded border-gray-700 bg-gray-900 text-red-500 focus:ring-red-500/30">
                <label for="remember" class="text-sm text-gray-400">Remember this device</label>
            </div>

            <button type="submit"
                class="w-full rounded-lg bg-red-600 hover:bg-red-700 text-white font-semibold py-2.5 text-sm transition focus:outline-none focus:ring-2 focus:ring-red-500/50">
                Sign in to Admin
            </button>
        </form>

        <p class="mt-8 text-center text-xs text-gray-700">
            <a href="{{ route('home') }}" class="hover:text-gray-500 transition">← Back to site</a>
        </p>
    </div>

</body>
</html>
