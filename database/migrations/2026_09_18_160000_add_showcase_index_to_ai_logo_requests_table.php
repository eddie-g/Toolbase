<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Logo Lab's showcase lists rows "where is_showcase and status =
     * completed order by created_at desc". Without an index on the flag MySQL
     * walked the whole table newest-first looking for them, and because the
     * query selects every column it loaded each passed row's result_data
     * (multi-megabyte provider responses) just to discard it: about a second
     * per page view, growing with every logo generated.
     */
    public function up(): void
    {
        Schema::table('ai_logo_requests', function (Blueprint $table) {
            $table->index(['is_showcase', 'status', 'created_at'], 'ai_logo_requests_showcase_index');
        });
    }

    public function down(): void
    {
        Schema::table('ai_logo_requests', function (Blueprint $table) {
            $table->dropIndex('ai_logo_requests_showcase_index');
        });
    }
};
