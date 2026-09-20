<x-filament-panels::page>
    @php
        // Styles live in resources/css/user-portal.css (nk-* classes), loaded
        // into the panel by UserPanelProvider.
        $status = request('status');
        $statusPlan = $plans->firstWhere('product_key', request('plan'));
        $callout = match ($status) {
            'success' => ['success', 'heroicon-o-check-circle', 'Payment successful', '$' . e(request('amount', '0')) . '.00 has been added to your balance. It may take a moment to show.'],
            'subscribed' => ['success', 'heroicon-o-check-circle', ($statusPlan?->name ?? 'Your plan') . ' is active', $statusPlan?->isOneTime()
                ? 'Every premium tool is unlocked for the next ' . ($statusPlan->duration_days ?: 7) . ' days.'
                : 'Every premium tool is unlocked. It renews monthly; cancel any time below.'],
            'cancelled' => ['warning', 'heroicon-o-exclamation-triangle', 'Checkout cancelled', 'No charges were made. You can try again whenever you like.'],
            'verification_failed' => ['danger', 'heroicon-o-exclamation-triangle', 'Payment could not be verified', 'We could not confirm the Stripe session. If you were charged, the purchase will still show up once Stripe notifies us.'],
            default => null,
        };
    @endphp

    <div
        class="nk-page"
        x-data="{
            loading: null,
            error: null,
            async post(url, body) {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(body),
                });
                return response.json();
            },
            async checkout(amount) {
                this.loading = 'amount:' + amount;
                this.error = null;
                try {
                    const data = await this.post('{{ route('credits.checkout') }}', { amount, source: 'portal' });
                    if (data.checkout_url) { window.location.href = data.checkout_url; return; }
                    this.error = data.error || 'Failed to create checkout session.';
                } catch (e) {
                    this.error = 'Network error. Please try again.';
                }
                this.loading = null;
            },
            async subscribe(planId) {
                this.loading = 'plan:' + planId;
                this.error = null;
                try {
                    const data = await this.post('{{ route('subscription.checkout') }}', { plan_id: planId, source: 'portal' });
                    if (data.checkout_url) { window.location.href = data.checkout_url; return; }
                    this.error = data.error || 'Failed to create checkout session.';
                } catch (e) {
                    this.error = 'Network error. Please try again.';
                }
                this.loading = null;
            },
            async cancelSub(subscriptionId) {
                if (!confirm('Cancel your monthly plan? Premium tools stay unlocked until the end of the current period.')) return;
                this.loading = 'cancel:' + subscriptionId;
                this.error = null;
                try {
                    const data = await this.post('{{ route('subscription.cancel') }}', { subscription_id: subscriptionId });
                    if (data.success) { window.location.reload(); return; }
                    this.error = data.error || 'Failed to cancel subscription.';
                } catch (e) {
                    this.error = 'Network error. Please try again.';
                }
                this.loading = null;
            }
        }"
    >
        {{-- Status after a Stripe redirect --}}
        @if($callout)
            <div class="nk-callout nk-callout-{{ $callout[0] }}">
                <x-dynamic-component :component="$callout[1]" />
                <div>
                    <p class="nk-callout-title">{{ $callout[2] }}</p>
                    <p class="nk-callout-body">{{ $callout[3] }}</p>
                </div>
            </div>
        @endif

        <template x-if="error">
            <div class="nk-callout nk-callout-danger">
                <x-heroicon-o-exclamation-triangle />
                <p x-text="error"></p>
            </div>
        </template>

        {{-- Balance --}}
        <div class="nk-card">
            <div class="nk-row">
                <div>
                    <p class="nk-muted">Current balance</p>
                    <p class="nk-amount nk-mt-1">${{ $balance }}</p>
                </div>
                <div class="nk-stack-end">
                    @if($activePlan)
                        <span class="nk-badge nk-badge-lime">
                            <x-heroicon-s-check-circle />
                            {{ $activePlan->plan->name }}
                        </span>
                        <p class="nk-small">
                            {{ $activePlan->plan->isOneTime() ? 'Expires' : 'Renews' }}
                            {{ $activePlan->current_period_end?->format('M j, Y') ?? 'automatically' }}
                        </p>
                    @else
                        <span class="nk-badge nk-badge-zinc">Pay as you go</span>
                        <p class="nk-small">No plan active</p>
                    @endif
                </div>
            </div>
        </div>

        {{-- Top up --}}
        <div class="nk-card">
            <h3 class="nk-heading">Add credits</h3>
            <p class="nk-muted nk-mt-1">Pick an amount. Stripe Checkout opens in this tab and credits land as soon as the payment clears.</p>

            <div class="nk-amounts nk-mt-5">
                @foreach($amounts as $amount)
                    <button type="button" class="nk-amount-btn" @click="checkout({{ $amount }})" :disabled="loading !== null">
                        <span class="nk-amount-value">${{ $amount }}</span>
                        <span class="nk-amount-unit">USD</span>
                        <template x-if="loading === 'amount:{{ $amount }}'">
                            <span class="nk-overlay"><x-filament::loading-indicator /></span>
                        </template>
                    </button>
                @endforeach
            </div>

            <p class="nk-small nk-mt-4">Payments are processed by Stripe. Netkit never sees your card details.</p>
        </div>

        {{-- Subscriptions --}}
        <div class="nk-card">
            <h3 class="nk-heading">Subscriptions</h3>
            <p class="nk-muted nk-mt-1">One plan unlocks every premium tool: the PDF editor, domain search and the logo generator.</p>

            @if($plans->isEmpty())
                <p class="nk-muted nk-mt-5">No plans are on offer right now.</p>
            @else
                <div class="nk-plans nk-mt-5">
                    @foreach($plans as $plan)
                        @php
                            $isActive = $activePlanKey === $plan->product_key;
                            // A running monthly plan already covers a week pass; the
                            // reverse (upgrading a pass to monthly) stays open.
                            $blockedByOther = $activePlan && ! $isActive && $plan->isOneTime();
                            $price = number_format($plan->price, 2);
                        @endphp
                        <div class="nk-plan {{ $isActive ? 'nk-plan-active' : '' }}">
                            <div class="nk-row-start">
                                <div>
                                    <p class="nk-plan-name">{{ $plan->name }}</p>
                                    <p class="nk-small nk-mt-1">{{ $plan->description }}</p>
                                </div>
                                @if($isActive)
                                    <span class="nk-badge nk-badge-lime">Active</span>
                                @else
                                    <span class="nk-badge nk-badge-zinc">{{ $plan->isOneTime() ? ($plan->duration_days ?: 7) . ' days' : 'Monthly' }}</span>
                                @endif
                            </div>

                            <p class="nk-mt-4">
                                <span class="nk-amount">${{ $price }}</span>
                                <span class="nk-plan-cadence">{{ $plan->isOneTime() ? 'one-time' : '/ month' }}</span>
                            </p>

                            @if($plan->features)
                                <ul class="nk-features">
                                    @foreach($plan->features as $feature)
                                        <li><x-heroicon-s-check /><span>{{ $feature }}</span></li>
                                    @endforeach
                                </ul>
                            @endif

                            <div class="nk-plan-actions">
                                @if($isActive && ! $plan->isOneTime())
                                    <button type="button" class="nk-btn nk-btn-danger" @click="cancelSub({{ $activePlan->id }})" :disabled="loading !== null">
                                        <span x-show="loading !== 'cancel:{{ $activePlan->id }}'">Cancel plan</span>
                                        <span x-show="loading === 'cancel:{{ $activePlan->id }}'" x-cloak>Cancelling…</span>
                                    </button>
                                    <p class="nk-small nk-center nk-mt-2">Renews {{ $activePlan->current_period_end?->format('M j, Y') }}</p>
                                @elseif($isActive)
                                    <button type="button" class="nk-btn nk-btn-outline" @click="subscribe({{ $plan->id }})" :disabled="loading !== null">
                                        <span x-show="loading !== 'plan:{{ $plan->id }}'">Add another week — ${{ $price }}</span>
                                        <span x-show="loading === 'plan:{{ $plan->id }}'" x-cloak>Redirecting…</span>
                                    </button>
                                    <p class="nk-small nk-center nk-mt-2">Expires {{ $activePlan->current_period_end?->format('M j, Y') }}</p>
                                @elseif($blockedByOther)
                                    <button type="button" class="nk-btn nk-btn-outline" disabled>Included in your plan</button>
                                @else
                                    <button type="button" class="nk-btn {{ $plan->isOneTime() ? 'nk-btn-outline' : 'nk-btn-primary' }}" @click="subscribe({{ $plan->id }})" :disabled="loading !== null">
                                        <span x-show="loading !== 'plan:{{ $plan->id }}'">{{ $plan->isOneTime() ? 'Buy week pass' : 'Subscribe' }} — ${{ $price }}</span>
                                        <span x-show="loading === 'plan:{{ $plan->id }}'" x-cloak>Redirecting…</span>
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <p class="nk-small nk-mt-4">The monthly plan is billed by Stripe and can be cancelled at any time. The week pass is a single payment and simply ends.</p>
            @endif
        </div>

        {{-- Recent deposits --}}
        @if($transactions->isNotEmpty())
            <div class="nk-card">
                <h3 class="nk-heading">Recent deposits</h3>
                <div class="nk-scroll nk-mt-4">
                    <table class="nk-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th class="nk-right">Amount</th>
                                <th class="nk-right">Balance after</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($transactions as $tx)
                                <tr>
                                    <td class="nk-nowrap">{{ $tx->created_at->format('M j, Y g:ia') }}</td>
                                    <td>{{ $tx->description }}</td>
                                    <td class="nk-right nk-pos">+${{ number_format($tx->amount, 2) }}</td>
                                    <td class="nk-right">${{ number_format($tx->balance_after, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
