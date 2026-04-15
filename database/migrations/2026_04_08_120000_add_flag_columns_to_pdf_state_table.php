<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdf_state', function (Blueprint $table) {
            $table->boolean('flagged')->default(false)->after('alignment');
            $table->text('flag_reason')->nullable()->after('flagged');
        });
    }

    public function down(): void
    {
        Schema::table('pdf_state', function (Blueprint $table) {
            $table->dropColumn(['flagged', 'flag_reason']);
        });
    }
};
