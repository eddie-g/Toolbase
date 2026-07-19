<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/netkit_logo_cube.svg') }}">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (() => {
            const saved = localStorage.getItem('darkMode');
            const useDark = saved === 'true' || (saved === null && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', useDark);
        })();
    </script>
    <title>Reset password - NETKIT</title>
    @vite(['resources/css/app.css'])
</head>
<body class="h-full bg-slate-50 text-slate-900 dark:bg-gray-950 dark:text-white">
    <main class="flex min-h-full items-center justify-center px-4 py-10">
        <div class="w-full max-w-sm">
            <div class="mb-8 text-center">
                <a href="{{ route('home') }}" class="inline-flex items-center justify-center gap-3 text-2xl font-bold text-slate-950 dark:text-white" style="text-decoration:none;">
                    <img src="{{ asset('images/netkit_logo_cube.svg') }}" alt="NETKIT logo" class="h-14 w-14 object-contain">
                    <span>NETKIT</span>
                </a>
                <p class="mt-2 text-sm text-slate-500 dark:text-gray-400">Choose a new password</p>
            </div>

            <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="token" value="{{ $request->route('token') }}">

                <div>
                    <label for="email" class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-gray-300">Email</label>
                    <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}" required autofocus autocomplete="email"
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
                    Reset password
                </button>
            </form>

            <p class="mt-6 text-center text-sm text-slate-500 dark:text-gray-500">
                <a href="{{ route('login') }}" class="text-blue-600 transition hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">Back to sign in</a>
            </p>
        </div>
    </main>
</body>
</html>
