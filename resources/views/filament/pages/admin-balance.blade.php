<x-filament-panels::page>

    {{-- Stripe status messages --}}
    @if(request('stripe_status') === 'success')
        <div class="rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 p-4 mb-6">
            <div class="flex items-center gap-3">
                <x-heroicon-o-check-circle class="w-6 h-6 text-emerald-500" />
                <div>
                    <p class="font-semibold text-emerald-800 dark:text-emerald-200">Payment successful!</p>
                    <p class="text-sm text-emerald-600 dark:text-emerald-400">
                        ${{ request('amount', '0') }}.00 has been submitted. Credits will be added once the Stripe webhook is processed.
                    </p>
                </div>
            </div>
        </div>
    @elseif(request('stripe_status') === 'cancelled')
        <div class="rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 p-4 mb-6">
            <div class="flex items-center gap-3">
                <x-heroicon-o-exclamation-triangle class="w-6 h-6 text-amber-500" />
                <div>
                    <p class="font-semibold text-amber-800 dark:text-amber-200">Payment cancelled</p>
                    <p class="text-sm text-amber-600 dark:text-amber-400">No charges were made.</p>
                </div>
            </div>
        </div>
    @endif

    {{-- Current balances table --}}
    <div class="rounded-xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-6 mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">All Admin Accounts</h3>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead>
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Name</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Email</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Credit Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($this->getAdmins() as $admin)
                        <tr>
                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">{{ $admin->name }}</td>
                            <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ $admin->email }}</td>
                            <td class="px-4 py-3 text-sm text-right font-mono {{ $admin->credit_balance > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400' }}">
                                ${{ number_format($admin->credit_balance, 4) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Add Credits via Stripe (for logged-in admin) --}}
    <div class="rounded-xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-6 mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Add Credits via Stripe</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">Purchase credits for your own admin account via Stripe checkout.</p>

        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
            @foreach($stripeAmounts as $amount)
                <button
                    wire:click="stripeCheckout({{ $amount }})"
                    wire:loading.attr="disabled"
                    class="relative flex flex-col items-center justify-center rounded-xl border-2 border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 p-5 transition-all duration-150
                           hover:border-emerald-500 hover:bg-emerald-50 dark:hover:border-emerald-400 dark:hover:bg-emerald-900/20
                           focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800
                           disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <span class="text-2xl font-bold text-gray-900 dark:text-white">${{ $amount }}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400 mt-1">USD</span>
                    <span wire:loading wire:target="stripeCheckout({{ $amount }})" class="absolute inset-0 flex items-center justify-center rounded-xl bg-white/80 dark:bg-gray-800/80">
                        <svg class="animate-spin h-6 w-6 text-emerald-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </span>
                </button>
            @endforeach
        </div>

        <p class="text-xs text-gray-400 dark:text-gray-500 mt-4">
            Payments are securely processed by Stripe. Credits are added after webhook confirmation.
        </p>
    </div>

    {{-- Manual top-up form --}}
    <div class="rounded-xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Manual Top Up</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">Add credits directly to any admin account without a payment.</p>

        <form wire:submit="topup">
            {{ $this->form }}

            <div class="mt-4">
                <x-filament::button type="submit">
                    Add Credits
                </x-filament::button>
            </div>
        </form>
    </div>

</x-filament-panels::page>
