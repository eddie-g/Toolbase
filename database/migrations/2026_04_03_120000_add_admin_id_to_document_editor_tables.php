<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (!Schema::hasColumn('documents', 'admin_id')) {
                $table->foreignId('admin_id')->nullable()->after('user_id')->constrained('admins')->nullOnDelete();
                $table->index('admin_id');
            }
        });

        Schema::table('pdf_state', function (Blueprint $table) {
            if (!Schema::hasColumn('pdf_state', 'admin_id')) {
                $table->foreignId('admin_id')->nullable()->after('user_id')->constrained('admins')->nullOnDelete();
                $table->index('admin_id');
                $table->index(['document_id', 'admin_id']);
            }
        });

        Schema::table('pdf_acro_form', function (Blueprint $table) {
            if (!Schema::hasColumn('pdf_acro_form', 'admin_id')) {
                $table->foreignId('admin_id')->nullable()->after('user_id')->constrained('admins')->nullOnDelete();
                $table->index('admin_id');
            }
        });

        DB::table('pdf_state')
            ->join('documents', 'documents.id', '=', 'pdf_state.document_id')
            ->whereNull('pdf_state.admin_id')
            ->whereNull('pdf_state.user_id')
            ->whereNotNull('documents.admin_id')
            ->update([
                'pdf_state.admin_id' => DB::raw('documents.admin_id'),
                'pdf_state.user_email' => null,
            ]);

        DB::table('pdf_acro_form')
            ->join('documents', 'documents.id', '=', 'pdf_acro_form.document_id')
            ->whereNull('pdf_acro_form.admin_id')
            ->whereNull('pdf_acro_form.user_id')
            ->whereNotNull('documents.admin_id')
            ->update([
                'pdf_acro_form.admin_id' => DB::raw('documents.admin_id'),
            ]);
    }

    public function down(): void
    {
        Schema::table('pdf_acro_form', function (Blueprint $table) {
            if (Schema::hasColumn('pdf_acro_form', 'admin_id')) {
                $table->dropIndex(['admin_id']);
                $table->dropForeign(['admin_id']);
                $table->dropColumn('admin_id');
            }
        });

        Schema::table('pdf_state', function (Blueprint $table) {
            if (Schema::hasColumn('pdf_state', 'admin_id')) {
                $table->dropIndex(['document_id', 'admin_id']);
                $table->dropIndex(['admin_id']);
                $table->dropForeign(['admin_id']);
                $table->dropColumn('admin_id');
            }
        });

        Schema::table('documents', function (Blueprint $table) {
            if (Schema::hasColumn('documents', 'admin_id')) {
                $table->dropIndex(['admin_id']);
                $table->dropForeign(['admin_id']);
                $table->dropColumn('admin_id');
            }
        });
    }
};
