<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="h-full flex items-center justify-center px-4">

    <div class="w-full max-w-sm">
        <div class="mb-8 text-center">
            <a href="{{ route('home') }}" class="inline-block text-2xl font-bold text-white mb-1">{{ config('app.name') }}</a>
            <p class="text-sm text-gray-500">Sign in to your account</p>
        </div>

        {{-- Session status (e.g. password reset success) --}}
        @if(session('status'))
            <div class="mb-4 rounded-lg bg-emerald-900/30 border border-emerald-700/50 px-4 py-3 text-sm text-emerald-400">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-xs font-medium text-gray-400 mb-1.5">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email"
                    class="w-full rounded-lg bg-gray-900 border text-white px-3.5 py-2.5 text-sm placeholder-gray-600
                           focus:outline-none focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500 transition
                           {{ $errors->has('email') ? 'border-red-500' : 'border-gray-700' }}">
                @error('email')
                    <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <label for="password" class="block text-xs font-medium text-gray-400">Password</label>
                    @if(Route::has('password.request'))
                        <a href="{{ route('password.request') }}" class="text-xs text-blue-500 hover:text-blue-400 transition">Forgot password?</a>
                    @endif
                </div>
                <input id="password" type="password" name="password" required autocomplete="current-password"
                    class="w-full rounded-lg bg-gray-900 border border-gray-700 text-white px-3.5 py-2.5 text-sm
                           focus:outline-none focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500 transition">
                @error('password')
                    <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-center gap-2">
                <input id="remember" type="checkbox" name="remember"
                    class="rounded border-gray-700 bg-gray-900 text-blue-500 focus:ring-blue-500/30">
                <label for="remember" class="text-sm text-gray-400">Remember me</label>
            </div>

            <button type="submit"
                class="w-full rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2.5 text-sm transition focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                Sign in
            </button>
        </form>

        {{-- Google OAuth --}}
        <div class="mt-5">
            <div class="relative flex items-center gap-3">
                <div class="flex-1 h-px bg-gray-800"></div>
                <span class="text-xs text-gray-600">Or continue with</span>
                <div class="flex-1 h-px bg-gray-800"></div>
            </div>
            <a href="{{ route('auth.google') }}"
               class="mt-4 flex w-full items-center justify-center gap-3 rounded-lg bg-gray-900 border border-gray-700 px-4 py-2.5 text-sm font-medium text-gray-200 hover:bg-gray-800 transition">
                <svg class="h-5 w-5 flex-shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M23.49 12.27c0-0.79-0.07-1.54-0.19-2.27H12v4.51h6.47c-0.29 1.48-1.14 2.73-2.4 3.58v3h3.86c2.26-2.09 3.56-5.17 3.56-8.82z" fill="#4285F4"/>
                    <path d="M12 24c3.24 0 5.95-1.08 7.92-2.91l-3.86-3c-1.08 0.72-2.45 1.16-4.06 1.16-3.13 0-5.78-2.11-6.73-4.96H1.29v3.09C3.26 21.3 7.31 24 12 24z" fill="#34A853"/>
                    <path d="M5.27 14.29c-0.25-0.74-0.39-1.54-0.39-2.29s0.14-1.55 0.39-2.29V6.62H1.29C0.47 8.24 0 10.06 0 12s0.47 3.76 1.29 5.38l3.98-3.09z" fill="#FBBC05"/>
                    <path d="M12 4.75c1.77 0 3.35 0.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.31 0 3.26 2.7 1.29 6.62l3.98 3.09c0.95-2.85 3.6-4.96 6.73-4.96z" fill="#EA4335"/>
                </svg>
                Google
            </a>
        </div>

        @if(Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::registration()))
            <p class="mt-6 text-center text-sm text-gray-600">
                No account?
                <a href="{{ route('register') }}" class="text-blue-500 hover:text-blue-400 transition">Create one</a>
            </p>
        @endif
    </div>

</body>
</html>
