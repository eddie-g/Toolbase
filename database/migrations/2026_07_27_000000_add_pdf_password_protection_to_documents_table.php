<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('pdf_password_hash')->nullable()->after('form_data');
            $table->string('pdf_password_algorithm', 16)->nullable()->after('pdf_password_hash');
            $table->timestamp('pdf_password_set_at')->nullable()->after('pdf_password_algorithm');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn([
                'pdf_password_hash',
                'pdf_password_algorithm',
                'pdf_password_set_at',
            ]);
        });
    }
};
