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
        Schema::table('ai_domain_requests', function (Blueprint $table) {
            // Remove session-based fields
            $table->dropColumn(['session', 'email', 'template']);
            
            // Add user_id with foreign key
            $table->foreignId('user_id')->after('id')->constrained()->onDelete('cascade');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_domain_requests', function (Blueprint $table) {
            // Remove user_id
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
            
            // Restore session fields
            $table->string('session')->nullable()->after('id');
            $table->string('email')->nullable()->after('session');
            $table->string('template')->nullable()->after('email');
        });
    }
};
