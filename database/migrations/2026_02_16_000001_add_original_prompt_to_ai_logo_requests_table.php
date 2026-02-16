<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_logo_requests', function (Blueprint $table) {
            $table->text('original_prompt')->nullable()->after('prompt');
        });
    }

    public function down(): void
    {
        Schema::table('ai_logo_requests', function (Blueprint $table) {
            $table->dropColumn('original_prompt');
        });
    }
};

