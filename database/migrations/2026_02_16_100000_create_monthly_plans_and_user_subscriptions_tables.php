<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_plans', function (Blueprint $table) {
            $table->id();
            $table->string('product_key')->unique();        // pdf-editor, domain-search, logo-generator
            $table->string('name');                           // Display name
            $table->text('description')->nullable();
            $table->decimal('price', 8, 2);                  // Monthly price in USD
            $table->string('stripe_price_id')->nullable();   // Stripe recurring price ID
            $table->json('features')->nullable();             // Feature list for display
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('monthly_plan_id')->constrained()->onDelete('cascade');
            $table->string('stripe_subscription_id')->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->string('status')->default('active');     // active, cancelled, past_due, incomplete
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'monthly_plan_id']);
        });

        // Seed dummy plans
        DB::table('monthly_plans')->insert([
            [
                'product_key' => 'pdf-editor',
                'name' => 'PDF Editor Pro',
                'description' => 'Full access to the PDF editor with unlimited exports, OCR, annotations, and AI-powered document tools.',
                'price' => 9.99,
                'stripe_price_id' => null,
                'features' => json_encode([
                    'Unlimited PDF editing',
                    'OCR text extraction',
                    'AI document assistant',
                    'Export to Word & Excel',
                    'PDF/A compliance conversion',
                ]),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_key' => 'domain-search',
                'name' => 'Domain Search Pro',
                'description' => 'Premium domain name search with AI-powered suggestions, bulk checking, and priority results.',
                'price' => 4.99,
                'stripe_price_id' => null,
                'features' => json_encode([
                    'Unlimited domain searches',
                    'AI domain suggestions',
                    'Bulk availability checking',
                    'Priority search results',
                    'Search history & favorites',
                ]),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_key' => 'logo-generator',
                'name' => 'Logo Generator Pro',
                'description' => 'Create unlimited AI-generated logos with pro mode, upscaling, background removal, and vectorization.',
                'price' => 14.99,
                'stripe_price_id' => null,
                'features' => json_encode([
                    'Unlimited logo generation',
                    'Pro mode (high quality)',
                    'Background removal',
                    'SVG vectorization',
                    'Logo upscaling',
                ]),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_subscriptions');
        Schema::dropIfExists('monthly_plans');
    }
};
