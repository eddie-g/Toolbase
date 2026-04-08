<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdf_state', function (Blueprint $table) {
            if (!Schema::hasColumn('pdf_state', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('document_id')->constrained()->nullOnDelete();
                $table->index('user_id');
                $table->index(['document_id', 'user_id']);
            }
        });

        if (Schema::hasColumn('pdf_state', 'user_id')) {
            DB::table('pdf_state')
                ->join('documents', 'documents.id', '=', 'pdf_state.document_id')
                ->whereNull('pdf_state.user_id')
                ->whereNotNull('documents.user_id')
                ->update([
                    'pdf_state.user_id' => DB::raw('documents.user_id'),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('pdf_state', function (Blueprint $table) {
            if (Schema::hasColumn('pdf_state', 'user_id')) {
                $table->dropIndex(['document_id', 'user_id']);
                $table->dropIndex(['user_id']);
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            }
        });
    }
};
