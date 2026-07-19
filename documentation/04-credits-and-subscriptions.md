# Credits & Subscriptions

Toolbase uses a **prepaid credit system** for AI feature usage (logo generation, domain AI, document generation) and **monthly subscriptions** for premium plan access. Both are processed through Stripe.

---

## Credit Balance

Every `User` and `Admin` has a `credit_balance` decimal column (4 decimal places, representing USD). Credits are consumed automatically whenever an AI feature runs, based on the model used and the number of tokens or images generated.

Every movement is recorded in `credit_transactions` — positive amounts for additions, negative for deductions. Each row includes a `type` (`stripe_payment`, `ai_cost`, `manual`, `refund`), a human-readable `description`, and an optional `stripe_payment_intent_id` for Stripe-originated payments.

---

## Adding Credits (Top-Up)

Users can top up their balance in fixed amounts: **$3, $5, $10, $20, $50, or $100**. Arbitrary amounts are rejected at validation.

### The top-up flow

```
1. User selects an amount on the top-up page.
2. POST /credits/checkout  →  Stripe Checkout session created.
3. User is redirected to Stripe's hosted payment page.
4. User completes payment.
5. Stripe fires a checkout.session.completed webhook to POST /credits/webhook.
6. CreditController verifies the Stripe signature, processes the session,
   increments credit_balance, and records a credit_transactions row.
7. User is also redirected to GET /credits/checkout/success as a fallback path.
```

### Security: webhook signature verification

The webhook handler calls `Stripe\Webhook::constructEvent()` with the raw request body and the `Stripe-Signature` header. Any mismatch returns HTTP 400, preventing forged requests from crediting accounts without a real payment.

### Idempotency

Both the webhook and the authenticated return path call the same `processCreditCheckoutSession()` method, which checks whether a `credit_transactions` row with the same `stripe_payment_intent_id` already exists before inserting. This means duplicate webhook deliveries never result in double-crediting.

---

## Consuming Credits

Before any AI request is executed, the `AICostCalculator` service estimates the cost and shows it to the user for confirmation. Only after confirmation does the platform make the external API call. The actual cost is then deducted and logged.

For example, when using AI document generation:

1. The controller builds the prompt and counts image sections.
2. `AICostCalculator::estimateTotalCost()` returns a breakdown (text tokens + image count).
3. If the user has not yet confirmed, the estimate is returned to the client — no API call is made.
4. Once confirmed, the API is called, the real cost is recorded in `ai_price_log`, and a negative `credit_transactions` entry deducts the amount from `credit_balance`.

### AI Rate Table

Per-model pricing lives in the `ai_rates` database table and can be updated without a code deploy. Each row stores the model name, input/output cost per 1,000 tokens, and a fixed per-image cost.

---

## Monthly Subscriptions

### Plans

The `monthly_plans` table defines available subscription tiers. Each plan has a `product_key` code identifier (e.g. `pdf_pro`), a display name, a monthly USD price, the Stripe `stripe_price_id` for recurring billing, and a JSON `features` array of capability flags the plan unlocks.

### Subscribing

Subscription checkout uses Stripe Checkout in `subscription` mode. When the `checkout.session.completed` webhook fires with `mode = 'subscription'`, `handleSubscriptionCheckoutCompleted()` creates a `user_subscriptions` row with `status = 'active'`, the Stripe subscription and customer IDs, and the current billing period dates.

### Checking subscription status

```php
// On the User model
$user->hasActiveSubscription('pdf_pro');

// On a UserSubscription instance
$subscription->isActive();  // status=active AND period_end is in the future
```

### Cancellation

When Stripe fires `customer.subscription.deleted`, the subscription row is updated: `status` is set to `cancelled` and `cancelled_at` is recorded. Features gated on an active subscription become unavailable immediately.

---

## Platform Balance Monitoring

Two internal ledger tables track the platform's own API account balances — completely separate from user credits:

- **`fal_balance_ledger`** — Fal.ai balance (Flux image generation).
- **`openai_balance_ledger`** — OpenAI balance (DALL-E, GPT).

These are updated periodically by `FalBalanceService` and `OpenAiBalanceService` and shown on the Filament admin dashboard so the team can monitor API spend.

---

## Stripe Setup

Required `.env` keys:

```
STRIPE_KEY=pk_live_...
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

In local development, use the Stripe CLI to forward webhooks:

```bash
stripe listen --forward-to localhost:8000/credits/webhook
```

---

## Manual Credit Adjustments

Admins can add or remove credits from any user account directly through the Filament admin panel, bypassing Stripe entirely. This creates a `credit_transactions` record with `type = 'manual'` and is useful for refunds, promotional grants, or correcting errors.
