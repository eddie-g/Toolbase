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
    <title>Verify email - NETKIT</title>
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
                <p class="mt-2 text-sm text-slate-500 dark:text-gray-400">Confirm your email address</p>
            </div>

            @if(session('status') === Laravel\Fortify\Fortify::VERIFICATION_LINK_SENT)
                <div class="mb-4 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-700/50 dark:bg-emerald-900/30 dark:text-emerald-300">
                    A fresh verification link has been sent to your email address.
                </div>
            @endif

            <div class="rounded-lg border border-slate-200 bg-white p-5 text-sm text-slate-600 shadow-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <p>Check your inbox for the verification link we sent to {{ Auth::user()?->email }}.</p>
                <p class="mt-3">Once your email is verified, you can continue to your portal.</p>
            </div>

            <form method="POST" action="{{ route('verification.send') }}" class="mt-4">
                @csrf
                <button type="submit"
                    class="w-full rounded-lg bg-blue-600 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                    Resend verification email
                </button>
            </form>

            <form method="POST" action="{{ route('logout') }}" class="mt-3">
                @csrf
                <button type="submit"
                    class="w-full rounded-lg border border-slate-300 bg-white py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-blue-500/30 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800">
                    Sign out
                </button>
            </form>
        </div>
    </main>
</body>
</html>