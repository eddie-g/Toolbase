<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_domains', function (Blueprint $table) {
            $table->boolean('is_available')->nullable()->after('domain');
            $table->boolean('is_premium')->nullable()->after('is_available');
            $table->decimal('premium_price', 12, 2)->nullable()->after('is_premium');
            $table->timestamp('checked_at')->nullable()->after('premium_price');
        });
    }

    public function down(): void
    {
        Schema::table('saved_domains', function (Blueprint $table) {
            $table->dropColumn(['is_available', 'is_premium', 'premium_price', 'checked_at']);
        });
    }
};
