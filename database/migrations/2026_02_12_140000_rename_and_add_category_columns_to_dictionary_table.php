<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dictionary', function (Blueprint $table) {
            // Rename existing columns with category_ prefix
            $table->renameColumn('space', 'category_space');
            $table->renameColumn('fantasy', 'category_fantasy');
            $table->renameColumn('tech', 'category_tech');
            $table->renameColumn('romance', 'category_romance');
            $table->renameColumn('scifi', 'category_scifi');
        });

        Schema::table('dictionary', function (Blueprint $table) {
            // Add new category columns
            $table->decimal('category_mystery', 5, 3)->default(0)->index()->after('category_scifi');
            $table->decimal('category_thriller', 5, 3)->default(0)->index()->after('category_mystery');
            $table->decimal('category_horror', 5, 3)->default(0)->index()->after('category_thriller');
            $table->decimal('category_adventure', 5, 3)->default(0)->index()->after('category_horror');
            $table->decimal('category_historical', 5, 3)->default(0)->index()->after('category_adventure');
            $table->decimal('category_drama', 5, 3)->default(0)->index()->after('category_historical');
            $table->decimal('category_action', 5, 3)->default(0)->index()->after('category_drama');
        });
    }

    public function down(): void
    {
        Schema::table('dictionary', function (Blueprint $table) {
            $table->dropColumn([
                'category_mystery',
                'category_thriller',
                'category_horror',
                'category_adventure',
                'category_historical',
                'category_drama',
                'category_action',
            ]);
        });

        Schema::table('dictionary', function (Blueprint $table) {
            $table->renameColumn('category_space', 'space');
            $table->renameColumn('category_fantasy', 'fantasy');
            $table->renameColumn('category_tech', 'tech');
            $table->renameColumn('category_romance', 'romance');
            $table->renameColumn('category_scifi', 'scifi');
        });
    }
};
