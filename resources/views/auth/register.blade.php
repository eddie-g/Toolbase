<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (() => {
            const saved = localStorage.getItem('darkMode');
            const useDark = saved === 'true' || (saved === null && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', useDark);
        })();
    </script>
    <title>Create account - NETKIT</title>
    @vite(['resources/css/app.css'])
</head>
<body class="h-full bg-slate-50 text-slate-900 dark:bg-gray-950 dark:text-white">
    <main class="flex min-h-full items-center justify-center px-4 py-10">
        <div class="w-full max-w-sm">
            <div class="mb-8 text-center">
                <a href="{{ route('home') }}" class="inline-flex items-center justify-center gap-3 text-2xl font-bold text-slate-950 dark:text-white" style="text-decoration:none;">
                    <img src="{{ asset('images/netkit_logo_black.svg') }}" alt="NETKIT logo" class="h-14 w-14 object-contain dark:hidden">
                    <img src="{{ asset('images/netkit_logo_white.svg') }}" alt="NETKIT logo" class="hidden h-14 w-14 object-contain dark:block">
                    <span>NETKIT</span>
                </a>
                <p class="mt-2 text-sm text-slate-500 dark:text-gray-400">Create your free account</p>
            </div>

            <form method="POST" action="{{ route('register') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="name" class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-gray-300">Name</label>
                    <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name"
                        class="w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder-slate-400 transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30 dark:bg-gray-900 dark:text-white dark:placeholder-gray-600 {{ $errors->has('name') ? 'border-red-500' : 'border-slate-300 dark:border-gray-700' }}">
                    @error('name')
                        <p class="mt-1.5 text-xs text-red-500 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="email" class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-gray-300">Email</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email"
                        class="w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder-slate-400 transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30 dark:bg-gray-900 dark:text-white dark:placeholder-gray-600 {{ $errors->has('email') ? 'border-red-500' : 'border-slate-300 dark:border-gray-700' }}">
                    @error('email')
                        <p class="mt-1.5 text-xs text-red-500 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-gray-300">Password</label>
                    <input id="password" type="password" name="password" required autocomplete="new-password"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                    @error('password')
                        <p class="mt-1.5 text-xs text-red-500 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password_confirmation" class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-gray-300">Confirm password</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                </div>

                <button type="submit"
                    class="w-full rounded-lg bg-blue-600 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                    Create account
                </button>
            </form>

            <div class="mt-5">
                <div class="relative flex items-center gap-3">
                    <div class="h-px flex-1 bg-slate-200 dark:bg-gray-800"></div>
                    <span class="text-xs text-slate-500 dark:text-gray-500">Or continue with</span>
                    <div class="h-px flex-1 bg-slate-200 dark:bg-gray-800"></div>
                </div>
                <a href="{{ route('auth.google') }}"
                   class="mt-4 flex w-full items-center justify-center gap-3 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800">
                    <svg class="h-5 w-5 flex-shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M23.49 12.27c0-0.79-0.07-1.54-0.19-2.27H12v4.51h6.47c-0.29 1.48-1.14 2.73-2.4 3.58v3h3.86c2.26-2.09 3.56-5.17 3.56-8.82z" fill="#4285F4"/>
                        <path d="M12 24c3.24 0 5.95-1.08 7.92-2.91l-3.86-3c-1.08 0.72-2.45 1.16-4.06 1.16-3.13 0-5.78-2.11-6.73-4.96H1.29v3.09C3.26 21.3 7.31 24 12 24z" fill="#34A853"/>
                        <path d="M5.27 14.29c-0.25-0.74-0.39-1.54-0.39-2.29s0.14-1.55 0.39-2.29V6.62H1.29C0.47 8.24 0 10.06 0 12s0.47 3.76 1.29 5.38l3.98-3.09z" fill="#FBBC05"/>
                        <path d="M12 4.75c1.77 0 3.35 0.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.31 0 3.26 2.7 1.29 6.62l3.98 3.09c0.95-2.85 3.6-4.96 6.73-4.96z" fill="#EA4335"/>
                    </svg>
                    Google
                </a>
            </div>

            <p class="mt-6 text-center text-sm text-slate-500 dark:text-gray-500">
                Already have an account?
                <a href="{{ route('login') }}" class="text-blue-600 transition hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">Sign in</a>
            </p>
        </div>
    </main>
</body>
</html>
