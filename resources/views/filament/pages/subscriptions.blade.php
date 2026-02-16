<x-filament-panels::page>
    {{-- Status messages from Stripe redirect --}}
    @if(request('status') === 'success')
        <div class="rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 p-4 mb-6">
            <div class="flex items-center gap-3">
                <x-heroicon-o-check-circle class="w-6 h-6 text-emerald-500" />
                <div>
                    <p class="font-semibold text-emerald-800 dark:text-emerald-200">Subscription activated!</p>
                    <p class="text-sm text-emerald-600 dark:text-emerald-400">
                        Your <strong>{{ str_replace('-', ' ', request('plan', 'plan')) }}</strong> subscription is now active.
                    </p>
                </div>
            </div>
        </div>
    @elseif(request('status') === 'cancelled')
        <div class="rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 p-4 mb-6">
            <div class="flex items-center gap-3">
                <x-heroicon-o-exclamation-triangle class="w-6 h-6 text-amber-500" />
                <div>
                    <p class="font-semibold text-amber-800 dark:text-amber-200">Checkout cancelled</p>
                    <p class="text-sm text-amber-600 dark:text-amber-400">No subscription was created. You can try again anytime.</p>
                </div>
            </div>
        </div>
    @endif

    <div
        x-data="{
            loading: null,
            error: null,
            cancelLoading: null,
            async subscribe(planId) {
                this.loading = planId;
                this.error = null;
                try {
                    const response = await fetch('{{ route('subscription.checkout') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '{{ csrf_token() }}',
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ plan_id: planId }),
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
            },
            async cancelSub(subscriptionId) {
                if (!confirm('Are you sure you want to cancel this subscription?')) return;
                this.cancelLoading = subscriptionId;
                this.error = null;
                try {
                    const response = await fetch('{{ route('subscription.cancel') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '{{ csrf_token() }}',
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ subscription_id: subscriptionId }),
                    });
                    const data = await response.json();
                    if (data.success) {
                        window.location.reload();
                    } else {
                        this.error = data.error || 'Failed to cancel subscription.';
                        this.cancelLoading = null;
                    }
                } catch (e) {
                    this.error = 'Network error. Please try again.';
                    this.cancelLoading = null;
                }
            }
        }"
    >
        {{-- Error display --}}
        <template x-if="error">
            <div class="rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 p-3 mb-6">
                <p class="text-sm text-red-700 dark:text-red-300" x-text="error"></p>
            </div>
        </template>

        {{-- Plan Cards --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            @foreach($plans as $plan)
                @php
                    $isSubscribed = $activeSubscriptions->has($plan->product_key);
                    $subscription = $isSubscribed ? $activeSubscriptions->get($plan->product_key) : null;
                    $iconMap = [
                        'pdf-editor' => 'heroicon-o-document-text',
                        'domain-search' => 'heroicon-o-globe-alt',
                        'logo-generator' => 'heroicon-o-paint-brush',
                    ];
                    $colorMap = [
                        'pdf-editor' => 'blue',
                        'domain-search' => 'violet',
                        'logo-generator' => 'amber',
                    ];
                    $icon = $iconMap[$plan->product_key] ?? 'heroicon-o-cube';
                    $color = $colorMap[$plan->product_key] ?? 'gray';
                @endphp
                <div class="relative rounded-xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-6 flex flex-col">
                    {{-- Active badge --}}
                    @if($isSubscribed)
                        <div class="absolute top-4 right-4">
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 dark:bg-emerald-900/30 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-400">
                                <x-heroicon-s-check-circle class="w-3.5 h-3.5" />
                                Active
                            </span>
                        </div>
                    @endif

                    {{-- Icon & Name --}}
                    <div class="flex items-center gap-3 mb-4">
                        <div class="h-12 w-12 rounded-lg bg-{{ $color }}-100 dark:bg-{{ $color }}-900/30 flex items-center justify-center">
                            <x-dynamic-component :component="$icon" class="w-6 h-6 text-{{ $color }}-600 dark:text-{{ $color }}-400" />
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $plan->name }}</h3>
                        </div>
                    </div>

                    {{-- Price --}}
                    <div class="mb-4">
                        <span class="text-3xl font-bold text-gray-900 dark:text-white">${{ number_format($plan->price, 2) }}</span>
                        <span class="text-sm text-gray-500 dark:text-gray-400">/month</span>
                    </div>

                    {{-- Description --}}
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-5">{{ $plan->description }}</p>

                    {{-- Features --}}
                    @if($plan->features)
                        <ul class="space-y-2 mb-6 flex-1">
                            @foreach($plan->features as $feature)
                                <li class="flex items-start gap-2 text-sm text-gray-600 dark:text-gray-300">
                                    <x-heroicon-s-check class="w-4 h-4 text-emerald-500 mt-0.5 shrink-0" />
                                    {{ $feature }}
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- Action Button --}}
                    @if($isSubscribed)
                        <button
                            @click="cancelSub({{ $subscription->id }})"
                            :disabled="cancelLoading === {{ $subscription->id }}"
                            class="w-full rounded-lg border-2 border-red-200 dark:border-red-700 bg-red-50 dark:bg-red-900/20 px-4 py-2.5 text-sm font-semibold text-red-700 dark:text-red-400 transition-colors
                                   hover:bg-red-100 dark:hover:bg-red-900/40 hover:border-red-300 dark:hover:border-red-600
                                   disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <template x-if="cancelLoading === {{ $subscription->id }}">
                                <span class="flex items-center justify-center gap-2">
                                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Cancelling…
                                </span>
                            </template>
                            <template x-if="cancelLoading !== {{ $subscription->id }}">
                                <span>Cancel Subscription</span>
                            </template>
                        </button>
                        @if($subscription->current_period_end)
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-2 text-center">
                                Renews {{ $subscription->current_period_end->format('M j, Y') }}
                            </p>
                        @endif
                    @else
                        <button
                            @click="subscribe({{ $plan->id }})"
                            :disabled="loading !== null"
                            class="w-full rounded-lg bg-gray-900 dark:bg-white px-4 py-2.5 text-sm font-semibold text-white dark:text-gray-900 transition-colors
                                   hover:bg-gray-700 dark:hover:bg-gray-200
                                   focus:outline-none focus:ring-2 focus:ring-gray-900 dark:focus:ring-white focus:ring-offset-2 dark:focus:ring-offset-gray-800
                                   disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <template x-if="loading === {{ $plan->id }}">
                                <span class="flex items-center justify-center gap-2">
                                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Redirecting…
                                </span>
                            </template>
                            <template x-if="loading !== {{ $plan->id }}">
                                <span>Subscribe — ${{ number_format($plan->price, 2) }}/mo</span>
                            </template>
                        </button>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="text-xs text-gray-400 dark:text-gray-500 mt-4">
            Subscriptions are billed monthly via Stripe. You can cancel anytime.
        </p>
    </div>
</x-filament-panels::page>
