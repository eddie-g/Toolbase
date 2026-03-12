<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tests_overlay_editor', function (Blueprint $table) {
            $table->string('test_key')->nullable()->after('test_type');
            $table->json('artifacts')->nullable()->after('warnings');

            $table->index('test_key');
        });
    }

    public function down(): void
    {
        Schema::table('tests_overlay_editor', function (Blueprint $table) {
            $table->dropIndex(['test_key']);
            $table->dropColumn(['test_key', 'artifacts']);
        });
    }
};
