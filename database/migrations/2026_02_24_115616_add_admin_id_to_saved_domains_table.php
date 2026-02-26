<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_domains', function (Blueprint $table) {
            // Must drop FK before dropping the unique index it depends on
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id', 'domain']);

            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreignId('admin_id')->nullable()->after('user_id')->constrained()->nullOnDelete();

            // Re-add FK on user_id (now nullable)
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->unique(['user_id', 'domain']);
            $table->unique(['admin_id', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::table('saved_domains', function (Blueprint $table) {
            $table->dropUnique(['admin_id', 'domain']);
            $table->dropForeign(['admin_id']);
            $table->dropColumn('admin_id');
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id', 'domain']);

            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['user_id', 'domain']);
        });
    }
};

