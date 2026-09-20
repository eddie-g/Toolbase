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
            // Credits granted when the plan is activated (each period for a
            // recurring plan), spendable on any tool.
            $table->decimal('included_credits', 8, 2)->default(0)->after('unlocks_all_products');
        });

        DB::table('monthly_plans')->where('product_key', 'all-access-week')->update([
            'price' => 1.99,
            'included_credits' => 2.00,
            'description' => 'Every premium tool for seven days, with $2.00 in credits to spend. One payment, nothing renews.',
            'features' => json_encode([
                '$2.00 in credits included',
                'Access to all premium PDF tools',
                'Logo generator pro mode',
                'Premium domain tools',
                'Ends after 7 days, no renewal',
            ]),
            'updated_at' => now(),
        ]);

        DB::table('monthly_plans')->where('product_key', 'all-access-month')->update([
            'price' => 6.99,
            'included_credits' => 2.00,
            'description' => 'Every premium tool, $2.00 in credits each month, and live support. Cancel anytime.',
            'features' => json_encode([
                '$2.00 in credits every month',
                '50% of your unused credits roll over each month',
                'Live support during business hours',
                'Access to all premium PDF tools',
                'Logo generator pro mode',
                'Premium domain tools',
                'Renews every month, cancel anytime',
            ]),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('monthly_plans')->where('product_key', 'all-access-week')->update([
            'price' => 2.00,
            'description' => 'Every premium tool for seven days. One payment, nothing renews.',
            'features' => json_encode([
                'PDF editor without limits',
                'Domain search and AI suggestions',
                'Logo generator pro mode',
                'Ends after 7 days, no renewal',
            ]),
        ]);

        DB::table('monthly_plans')->where('product_key', 'all-access-month')->update([
            'price' => 5.99,
            'description' => 'Every premium tool in Netkit, billed monthly. Cancel anytime.',
            'features' => json_encode([
                'PDF editor without limits',
                'Domain search and AI suggestions',
                'Logo generator pro mode',
                'Renews every month, cancel anytime',
            ]),
        ]);

        Schema::table('monthly_plans', function (Blueprint $table) {
            $table->dropColumn('included_credits');
        });
    }
};
