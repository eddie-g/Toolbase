<?php

namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use App\Models\MonthlyPlan;
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
        ]);

        $amount = (int) $request->input('amount');

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
            'success_url' => url('/admin/add-credits') . '?status=success&amount=' . $amount,
            'cancel_url' => url('/admin/add-credits') . '?status=cancelled',
            'client_reference_id' => (string) $user->id,
            'metadata' => [
                'user_id' => $user->id,
                'credit_amount' => $amount,
            ],
        ]);

        return response()->json([
            'checkout_url' => $session->url,
            'session_id' => $session->id,
        ]);
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

            $userId = $session->metadata->user_id ?? $session->client_reference_id ?? null;
            $creditAmount = $session->metadata->credit_amount ?? null;

            if (!$userId || !$creditAmount) {
                \Log::warning('Stripe webhook: missing user_id or credit_amount', [
                    'session_id' => $session->id,
                ]);
                return response('Missing metadata', 400);
            }

            $user = \App\Models\User::find($userId);
            if (!$user) {
                \Log::warning('Stripe webhook: user not found', ['user_id' => $userId]);
                return response('User not found', 404);
            }

            // Prevent duplicate processing — check if this session was already handled
            $existing = CreditTransaction::where('description', 'LIKE', '%' . $session->id . '%')->first();
            if ($existing) {
                \Log::info('Stripe webhook: duplicate session, skipping', ['session_id' => $session->id]);
                return response('Already processed', 200);
            }

            $creditAmount = (float) $creditAmount;

            CreditTransaction::topup(
                userId: $user->id,
                amount: $creditAmount,
                description: "Stripe deposit \${$creditAmount} (session: {$session->id})",
                metadata: [
                    'stripe_session_id' => $session->id,
                    'stripe_payment_intent' => $session->payment_intent ?? null,
                    'amount_usd' => $creditAmount,
                ],
            );

            \Log::info('Stripe webhook: credits added', [
                'user_id' => $userId,
                'amount' => $creditAmount,
                'session_id' => $session->id,
            ]);
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
