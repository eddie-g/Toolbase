<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_domain_requests', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('user_id');
            $table->json('tlds')->nullable()->after('prompt');
            $table->longText('result_data')->nullable()->after('response');
            $table->text('error_message')->nullable()->after('result_data');
        });
    }

    public function down(): void
    {
        Schema::table('ai_domain_requests', function (Blueprint $table) {
            $table->dropColumn(['status', 'tlds', 'result_data', 'error_message']);
        });
    }
};
