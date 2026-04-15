<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('pdf_state', function (Blueprint $table) {
            $table->longText('flag_images')->nullable()->after('flag_reason');
        });
    }
    public function down(): void {
        Schema::table('pdf_state', function (Blueprint $table) {
            $table->dropColumn('flag_images');
        });
    }
};
