<?php

namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use App\Models\MonthlyPlan;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Webhook;

class CreditController extends Controller
{
    /**
     * Allowed deposit amounts in USD.
     */
    private const ALLOWED_AMOUNTS = [3, 5, 10, 20, 50, 100];

    /**
     * Create a Stripe Checkout session for a credit top-up.
     */
    public function createCheckout(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'You must be logged in.'], 401);
        }

        $request->validate([
            'amount' => ['required', 'numeric', 'in:' . implode(',', self::ALLOWED_AMOUNTS)],
            'source' => ['nullable', 'in:admin,portal'],
        ]);

        $amount = (int) $request->input('amount');
        $source = $request->input('source') === 'portal' ? 'portal' : 'admin';
        $basePath = $source === 'portal' ? '/portal/add-credits' : '/admin/add-credits';

        Stripe::setApiKey(config('services.stripe.secret'));

        $session = StripeSession::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => "Netkit Credits — \${$amount}",
                        'description' => "Add \${$amount}.00 to your Netkit credit balance",
                    ],
                    'unit_amount' => $amount * 100, // cents
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => url('/credits/checkout/success') . '?source=' . $source . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => url($basePath) . '?status=cancelled',
            'client_reference_id' => (string) $user->id,
            'metadata' => [
                'user_id' => $user->id,
                'credit_amount' => $amount,
                'source' => $source,
            ],
        ]);

        return response()->json([
            'checkout_url' => $session->url,
            'session_id' => $session->id,
        ]);
    }

    /**
     * Verify a returned Stripe Checkout session and apply credits.
     *
     * Webhooks remain the source of truth in production, but localhost/test
     * checkout needs an authenticated return path because Stripe cannot post to
     * localhost without CLI forwarding.
     */
    public function checkoutSuccess(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return redirect()->route('login');
        }

        $request->validate([
            'session_id' => ['required', 'string'],
            'source' => ['nullable', 'in:admin,portal'],
        ]);

        $source = $request->input('source') === 'portal' ? 'portal' : 'admin';
        $basePath = $source === 'portal' ? '/portal/add-credits' : '/admin/add-credits';

        Stripe::setApiKey(config('services.stripe.secret'));

        try {
            $session = StripeSession::retrieve($request->input('session_id'));
        } catch (\Throwable $e) {
            \Log::error('Stripe checkout return: failed to retrieve session', [
                'session_id' => $request->input('session_id'),
                'error' => $e->getMessage(),
            ]);

            return redirect($basePath . '?status=verification_failed');
        }

        $result = $this->processCreditCheckoutSession($session, $user->id, 'checkout_return');

        if ($result['status'] === 'credited' || $result['status'] === 'already_processed') {
            return redirect($basePath . '?status=success&amount=' . $result['amount']);
        }

        \Log::warning('Stripe checkout return: session was not credited', [
            'session_id' => $session->id ?? null,
            'status' => $result['status'],
            'reason' => $result['reason'] ?? null,
        ]);

        return redirect($basePath . '?status=verification_failed');
    }

    /**
     * Handle Stripe webhook events (payment completed).
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (\UnexpectedValueException $e) {
            \Log::error('Stripe webhook: invalid payload', ['error' => $e->getMessage()]);
            return response('Invalid payload', 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            \Log::error('Stripe webhook: invalid signature', ['error' => $e->getMessage()]);
            return response('Invalid signature', 400);
        }

        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;

            // Handle subscription checkout vs one-time credit purchase
            if (($session->mode ?? null) === 'subscription') {
                $this->handleSubscriptionCheckoutCompleted($session);
                return response('OK', 200);
            }

            // Handle admin top-up via Stripe
            $type = $session->metadata->type ?? null;
            if ($type === 'admin_topup') {
                $this->handleAdminTopupCheckoutCompleted($session);
                return response('OK', 200);
            }

            $result = $this->processCreditCheckoutSession($session, null, 'webhook');

            if ($result['status'] === 'missing_metadata') {
                return response('Missing metadata', 400);
            }

            if ($result['status'] === 'user_not_found') {
                return response('User not found', 404);
            }
        }

        // Handle subscription cancellation from Stripe
        if ($event->type === 'customer.subscription.deleted') {
            $subscription = $event->data->object;
            $this->handleSubscriptionCancelled($subscription);
        }

        // Handle failed payment on subscription
        if ($event->type === 'invoice.payment_failed') {
            $invoice = $event->data->object;
            $stripeSubId = $invoice->subscription ?? null;
            if ($stripeSubId) {
                UserSubscription::where('stripe_subscription_id', $stripeSubId)
                    ->update(['status' => 'past_due']);
                \Log::warning('Stripe webhook: subscription payment failed', [
                    'stripe_subscription_id' => $stripeSubId,
                ]);
            }
        }

        return response('OK', 200);
    }

    /**
     * Apply credits from a completed one-time checkout session.
     */
    private function processCreditCheckoutSession($session, ?int $expectedUserId = null, string $handledBy = 'webhook'): array
    {
        if (($session->mode ?? null) !== 'payment') {
            return ['status' => 'not_payment_session', 'reason' => 'mode_not_payment'];
        }

        if (($session->payment_status ?? null) !== 'paid') {
            return ['status' => 'not_paid', 'reason' => 'payment_status_not_paid'];
        }

        $userId = $session->metadata->user_id ?? $session->client_reference_id ?? null;
        $creditAmount = $session->metadata->credit_amount ?? null;

        if (!$userId || !$creditAmount) {
            \Log::warning('Stripe checkout: missing user_id or credit_amount', [
                'session_id' => $session->id ?? null,
            ]);

            return ['status' => 'missing_metadata'];
        }

        if ($expectedUserId !== null && (int) $userId !== $expectedUserId) {
            return ['status' => 'wrong_user', 'reason' => 'metadata_user_mismatch'];
        }

        if (!is_numeric($creditAmount)) {
            return ['status' => 'invalid_amount', 'reason' => 'amount_not_numeric'];
        }

        $creditAmount = (float) $creditAmount;
        if (!in_array($creditAmount, array_map('floatval', self::ALLOWED_AMOUNTS), true)) {
            return ['status' => 'invalid_amount', 'reason' => 'amount_not_allowed'];
        }

        $user = User::find($userId);
        if (!$user) {
            \Log::warning('Stripe checkout: user not found', ['user_id' => $userId]);
            return ['status' => 'user_not_found'];
        }

        $existing = CreditTransaction::where('metadata->stripe_session_id', $session->id)->first();
        if (!$existing) {
            $existing = CreditTransaction::where('description', 'LIKE', '%' . $session->id . '%')->first();
        }

        if ($existing) {
            \Log::info('Stripe checkout: duplicate session, skipping', [
                'session_id' => $session->id,
                'handled_by' => $handledBy,
            ]);

            return ['status' => 'already_processed', 'amount' => $creditAmount];
        }

        CreditTransaction::topup(
            userId: $user->id,
            amount: $creditAmount,
            description: "Stripe deposit \${$creditAmount} (session: {$session->id})",
            metadata: [
                'stripe_session_id' => $session->id,
                'stripe_payment_intent' => $session->payment_intent ?? null,
                'amount_usd' => $creditAmount,
                'handled_by' => $handledBy,
            ],
        );

        \Log::info('Stripe checkout: credits added', [
            'user_id' => $userId,
            'amount' => $creditAmount,
            'session_id' => $session->id,
            'handled_by' => $handledBy,
        ]);

        return ['status' => 'credited', 'amount' => $creditAmount];
    }

    /**
     * Handle a completed Stripe checkout for an admin top-up.
     */
    private function handleAdminTopupCheckoutCompleted($session): void
    {
        $adminId = $session->metadata->admin_id ?? null;
        $creditAmount = $session->metadata->credit_amount ?? null;

        if (!$adminId || !$creditAmount) {
            \Log::warning('Stripe webhook: admin_topup missing metadata', ['session_id' => $session->id]);
            return;
        }

        $admin = \App\Models\Admin::find($adminId);
        if (!$admin) {
            \Log::warning('Stripe webhook: admin not found', ['admin_id' => $adminId]);
            return;
        }

        $admin->topupBalance((float) $creditAmount);

        \Log::info('Stripe webhook: admin credits added', [
            'admin_id'   => $adminId,
            'amount'     => $creditAmount,
            'session_id' => $session->id,
        ]);
    }

    /**
     * Handle a completed subscription checkout session.
     */
    private function handleSubscriptionCheckoutCompleted($session): void
    {
        $userId = $session->metadata->user_id ?? $session->client_reference_id ?? null;
        $planId = $session->metadata->plan_id ?? null;
        $stripeSubscriptionId = $session->subscription ?? null;
        $stripeCustomerId = $session->customer ?? null;

        if (!$userId || !$planId) {
            \Log::warning('Stripe webhook: subscription missing metadata', [
                'session_id' => $session->id,
            ]);
            return;
        }

        $plan = MonthlyPlan::find($planId);
        if (!$plan) {
            \Log::warning('Stripe webhook: plan not found', ['plan_id' => $planId]);
            return;
        }

        // Prevent duplicate
        $existing = UserSubscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
        if ($existing) {
            \Log::info('Stripe webhook: subscription already exists', [
                'stripe_subscription_id' => $stripeSubscriptionId,
            ]);
            return;
        }

        UserSubscription::create([
            'user_id' => $userId,
            'monthly_plan_id' => $plan->id,
            'stripe_subscription_id' => $stripeSubscriptionId,
            'stripe_customer_id' => $stripeCustomerId,
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        \Log::info('Stripe webhook: subscription created', [
            'user_id' => $userId,
            'plan' => $plan->product_key,
            'stripe_subscription_id' => $stripeSubscriptionId,
        ]);
    }

    /**
     * Handle subscription cancellation from Stripe.
     */
    private function handleSubscriptionCancelled($subscription): void
    {
        $sub = UserSubscription::where('stripe_subscription_id', $subscription->id)->first();
        if ($sub) {
            $sub->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
            \Log::info('Stripe webhook: subscription cancelled', [
                'stripe_subscription_id' => $subscription->id,
            ]);
        }
    }

    /**
     * Create a Stripe Checkout session for a monthly subscription.
     */
    public function createSubscriptionCheckout(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'You must be logged in.'], 401);
        }

        $request->validate([
            'plan_id' => ['required', 'integer', 'exists:monthly_plans,id'],
        ]);

        $plan = MonthlyPlan::where('active', true)->findOrFail($request->input('plan_id'));

        // Check if user already has an active subscription for this plan
        $existing = UserSubscription::where('user_id', $user->id)
            ->where('monthly_plan_id', $plan->id)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            return response()->json(['error' => 'You already have an active subscription for this product.'], 409);
        }

        if (!$plan->stripe_price_id) {
            return response()->json(['error' => 'This plan is not yet available for purchase. Stripe price ID not configured.'], 422);
        }

        Stripe::setApiKey(config('services.stripe.secret'));

        // Get or create Stripe customer
        $stripeCustomerId = $this->getOrCreateStripeCustomer($user);

        $session = StripeSession::create([
            'payment_method_types' => ['card'],
            'customer' => $stripeCustomerId,
            'line_items' => [[
                'price' => $plan->stripe_price_id,
                'quantity' => 1,
            ]],
            'mode' => 'subscription',
            'success_url' => url('/admin/subscriptions') . '?status=success&plan=' . $plan->product_key,
            'cancel_url' => url('/admin/subscriptions') . '?status=cancelled',
            'client_reference_id' => (string) $user->id,
            'metadata' => [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'product_key' => $plan->product_key,
            ],
            'subscription_data' => [
                'metadata' => [
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'product_key' => $plan->product_key,
                ],
            ],
        ]);

        return response()->json([
            'checkout_url' => $session->url,
            'session_id' => $session->id,
        ]);
    }

    /**
     * Cancel an active subscription.
     */
    public function cancelSubscription(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'You must be logged in.'], 401);
        }

        $request->validate([
            'subscription_id' => ['required', 'integer', 'exists:user_subscriptions,id'],
        ]);

        $subscription = UserSubscription::where('user_id', $user->id)
            ->where('id', $request->input('subscription_id'))
            ->where('status', 'active')
            ->firstOrFail();

        if ($subscription->stripe_subscription_id) {
            try {
                Stripe::setApiKey(config('services.stripe.secret'));
                $stripeSub = \Stripe\Subscription::retrieve($subscription->stripe_subscription_id);
                $stripeSub->cancel();
            } catch (\Exception $e) {
                \Log::error('Stripe subscription cancel failed', [
                    'error' => $e->getMessage(),
                    'subscription_id' => $subscription->id,
                ]);
            }
        }

        $subscription->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Subscription cancelled.']);
    }

    /**
     * Get or create a Stripe customer for the given user.
     */
    private function getOrCreateStripeCustomer($user): string
    {
        // Check if user already has a Stripe customer ID from a previous subscription
        $existingSub = UserSubscription::where('user_id', $user->id)
            ->whereNotNull('stripe_customer_id')
            ->first();

        if ($existingSub) {
            return $existingSub->stripe_customer_id;
        }

        $customer = \Stripe\Customer::create([
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => ['user_id' => $user->id],
        ]);

        return $customer->id;
    }
}
