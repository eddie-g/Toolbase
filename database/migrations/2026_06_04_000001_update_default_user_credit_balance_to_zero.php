<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'credit_balance')) {
            return;
        }

        match (DB::getDriverName()) {
            'mysql', 'mariadb' => DB::statement('ALTER TABLE users MODIFY credit_balance DECIMAL(10, 4) NOT NULL DEFAULT 0'),
            'pgsql' => DB::statement('ALTER TABLE users ALTER COLUMN credit_balance SET DEFAULT 0'),
            'sqlite' => null,
            default => null,
        };

        DB::table('users')
            ->where('credit_balance', 10)
            ->update(['credit_balance' => 0]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'credit_balance')) {
            return;
        }

        match (DB::getDriverName()) {
            'mysql', 'mariadb' => DB::statement('ALTER TABLE users MODIFY credit_balance DECIMAL(10, 4) NOT NULL DEFAULT 10.0000'),
            'pgsql' => DB::statement('ALTER TABLE users ALTER COLUMN credit_balance SET DEFAULT 10.0000'),
            'sqlite' => null,
            default => null,
        };
    }
};