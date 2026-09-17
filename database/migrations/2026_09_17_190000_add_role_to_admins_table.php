<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every admins row used to be a full super-admin. Rows now need an explicit
 * role to enter the panel: owner, admin or support. The first admin becomes
 * the owner, the rest admins, so nobody is locked out by the migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->string('role', 20)->nullable()->after('password')->index();
        });

        $first = DB::table('admins')->orderBy('id')->value('id');
        if ($first !== null) {
            DB::table('admins')->where('id', $first)->update(['role' => 'owner']);
            DB::table('admins')->where('id', '!=', $first)->whereNull('role')->update(['role' => 'admin']);
        }
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
