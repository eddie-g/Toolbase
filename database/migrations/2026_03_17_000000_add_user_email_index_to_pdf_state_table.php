<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdf_state', function (Blueprint $table) {
            $table->index('user_email');
            $table->index(['state', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('pdf_state', function (Blueprint $table) {
            $table->dropIndex(['user_email']);
            $table->dropIndex(['state', 'updated_at']);
        });
    }
};
