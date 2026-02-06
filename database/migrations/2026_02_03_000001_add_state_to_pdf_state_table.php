<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pdf_state', function (Blueprint $table) {
            $table->string('state')->default('not_saved')->after('annotation_data'); // 'not_saved' or 'saved'
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pdf_state', function (Blueprint $table) {
            $table->dropColumn('state');
        });
    }
};
