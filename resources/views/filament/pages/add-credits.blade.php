<x-filament-panels::page>
    {{-- Status messages from Stripe redirect --}}
    @if(request('status') === 'success')
        <div class="rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 p-4 mb-6">
            <div class="flex items-center gap-3">
                <x-heroicon-o-check-circle class="w-6 h-6 text-emerald-500" />
                <div>
                    <p class="font-semibold text-emerald-800 dark:text-emerald-200">Payment successful!</p>
                    <p class="text-sm text-emerald-600 dark:text-emerald-400">
                        ${{ request('amount', '0') }}.00 has been added to your balance. It may take a moment to reflect.
                    </p>
                </div>
            </div>
        </div>
    @elseif(request('status') === 'cancelled')
        <div class="rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 p-4 mb-6">
            <div class="flex items-center gap-3">
                <x-heroicon-o-exclamation-triangle class="w-6 h-6 text-amber-500" />
                <div>
                    <p class="font-semibold text-amber-800 dark:text-amber-200">Payment cancelled</p>
                    <p class="text-sm text-amber-600 dark:text-amber-400">No charges were made. You can try again anytime.</p>
                </div>
            </div>
        </div>
    @endif

    {{-- Current Balance --}}
    <div class="rounded-xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-6 mb-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Current Balance</p>
                <p class="text-4xl font-bold text-gray-900 dark:text-white mt-1">${{ $balance }}</p>
            </div>
            <div class="h-14 w-14 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center">
                <x-heroicon-o-banknotes class="w-7 h-7 text-emerald-600 dark:text-emerald-400" />
            </div>
        </div>
    </div>

    {{-- Deposit Buttons --}}
    <div class="rounded-xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-6 mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Add Credits</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">Select an amount to add to your balance via Stripe checkout.</p>

        <div
            x-data="{
                loading: null,
                error: null,
                async checkout(amount) {
                    this.loading = amount;
                    this.error = null;
                    try {
                        const response = await fetch('{{ route('credits.checkout') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '{{ csrf_token() }}',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({ amount }),
                        });
                        const data = await response.json();
                        if (data.checkout_url) {
                            window.location.href = data.checkout_url;
                        } else {
                            this.error = data.error || 'Failed to create checkout session.';
                            this.loading = null;
                        }
                    } catch (e) {
                        this.error = 'Network error. Please try again.';
                        this.loading = null;
                    }
                }
            }"
        >
            {{-- Error display --}}
            <template x-if="error">
                <div class="rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 p-3 mb-4">
                    <p class="text-sm text-red-700 dark:text-red-300" x-text="error"></p>
                </div>
            </template>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                @foreach($amounts as $amount)
                    <button
                        @click="checkout({{ $amount }})"
                        :disabled="loading !== null"
                        class="relative flex flex-col items-center justify-center rounded-xl border-2 border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 p-5 transition-all duration-150
                               hover:border-emerald-500 hover:bg-emerald-50 dark:hover:border-emerald-400 dark:hover:bg-emerald-900/20
                               focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800
                               disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <span class="text-2xl font-bold text-gray-900 dark:text-white">${{ $amount }}</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400 mt-1">USD</span>

                        {{-- Loading spinner --}}
                        <template x-if="loading === {{ $amount }}">
                            <div class="absolute inset-0 flex items-center justify-center rounded-xl bg-white/80 dark:bg-gray-800/80">
                                <svg class="animate-spin h-6 w-6 text-emerald-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </div>
                        </template>
                    </button>
                @endforeach
            </div>

            <p class="text-xs text-gray-400 dark:text-gray-500 mt-4">
                Payments are securely processed by Stripe. Credits are added instantly after payment.
            </p>
        </div>
    </div>

    {{-- Recent Top-up Transactions --}}
    @if($transactions->isNotEmpty())
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Recent Deposits</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="text-left py-2 px-3 font-medium text-gray-500 dark:text-gray-400">Date</th>
                            <th class="text-left py-2 px-3 font-medium text-gray-500 dark:text-gray-400">Description</th>
                            <th class="text-right py-2 px-3 font-medium text-gray-500 dark:text-gray-400">Amount</th>
                            <th class="text-right py-2 px-3 font-medium text-gray-500 dark:text-gray-400">Balance After</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($transactions as $tx)
                            <tr class="border-b border-gray-100 dark:border-gray-700/50">
                                <td class="py-2.5 px-3 text-gray-600 dark:text-gray-300">{{ $tx->created_at->format('M j, Y g:ia') }}</td>
                                <td class="py-2.5 px-3 text-gray-600 dark:text-gray-300">{{ $tx->description }}</td>
                                <td class="py-2.5 px-3 text-right font-medium text-emerald-600 dark:text-emerald-400">+${{ number_format($tx->amount, 2) }}</td>
                                <td class="py-2.5 px-3 text-right text-gray-600 dark:text-gray-300">${{ number_format($tx->balance_after, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
