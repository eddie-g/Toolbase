<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_notes', function (Blueprint $table) {
            $table->string('pin_color', 16)->default('#2563eb')->after('anchor_y');
            $table->string('pin_icon', 32)->default('note')->after('pin_color');
        });
    }

    public function down(): void
    {
        Schema::table('document_notes', function (Blueprint $table) {
            $table->dropColumn(['pin_color', 'pin_icon']);
        });
    }
};
