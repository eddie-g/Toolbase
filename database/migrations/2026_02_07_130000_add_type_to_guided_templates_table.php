<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guided_templates', function (Blueprint $table) {
            $table->string('type')->default('invoice')->after('name');
        });

        // Update existing records to set type as 'invoice'
        DB::table('guided_templates')->update(['type' => 'invoice']);
    }

    public function down(): void
    {
        Schema::table('guided_templates', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
