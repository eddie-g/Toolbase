<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_plans', function (Blueprint $table) {
            // subscription = recurring Stripe price, one_time = a pass paid once
            // that unlocks for `duration_days`.
            $table->string('billing_type')->default('subscription')->after('price');
            $table->unsignedInteger('duration_days')->nullable()->after('billing_type');
            $table->boolean('unlocks_all_products')->default(false)->after('duration_days');
        });

        Schema::table('user_subscriptions', function (Blueprint $table) {
            // One-time passes have no Stripe subscription; the checkout session
            // id is what makes the webhook and the return URL idempotent.
            $table->string('stripe_checkout_session_id')->nullable()->after('stripe_customer_id');
        });

        $now = now();

        DB::table('monthly_plans')->insert([
            [
                'product_key' => 'all-access-month',
                'name' => 'All Access — 1 month',
                'description' => 'Every premium tool in Netkit, billed monthly. Cancel anytime.',
                'price' => 5.99,
                'billing_type' => 'subscription',
                'duration_days' => null,
                'unlocks_all_products' => true,
                'stripe_price_id' => config('services.stripe.all_access_month_price_id'),
                'features' => json_encode([
                    'PDF editor without limits',
                    'Domain search and AI suggestions',
                    'Logo generator pro mode',
                    'Renews every month, cancel anytime',
                ]),
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'product_key' => 'all-access-week',
                'name' => 'All Access — 1 week pass',
                'description' => 'Every premium tool for seven days. One payment, nothing renews.',
                'price' => 2.00,
                'billing_type' => 'one_time',
                'duration_days' => 7,
                'unlocks_all_products' => true,
                'stripe_price_id' => null,
                'features' => json_encode([
                    'PDF editor without limits',
                    'Domain search and AI suggestions',
                    'Logo generator pro mode',
                    'Ends after 7 days, no renewal',
                ]),
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('monthly_plans')->whereIn('product_key', ['all-access-month', 'all-access-week'])->delete();

        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->dropColumn('stripe_checkout_session_id');
        });

        Schema::table('monthly_plans', function (Blueprint $table) {
            $table->dropColumn(['billing_type', 'duration_days', 'unlocks_all_products']);
        });
    }
};
