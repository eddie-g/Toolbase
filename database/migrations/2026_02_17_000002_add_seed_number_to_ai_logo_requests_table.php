<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_logo_requests', function (Blueprint $table) {
            $table->bigInteger('seed_number')->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('ai_logo_requests', function (Blueprint $table) {
            $table->dropColumn('seed_number');
        });
    }
};

