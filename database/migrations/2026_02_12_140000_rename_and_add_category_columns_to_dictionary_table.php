<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyCategories = [
            'space' => 'category_space',
            'fantasy' => 'category_fantasy',
            'tech' => 'category_tech',
            'romance' => 'category_romance',
            'scifi' => 'category_scifi',
        ];
        $previousColumn = 'popularity';

        foreach ($legacyCategories as $legacy => $category) {
            if (Schema::hasColumn('dictionary', $legacy)
                && !Schema::hasColumn('dictionary', $category)) {
                Schema::table('dictionary', function (Blueprint $table) use ($legacy, $category) {
                    $table->renameColumn($legacy, $category);
                });
            } elseif (!Schema::hasColumn('dictionary', $category)) {
                Schema::table('dictionary', function (Blueprint $table) use ($category, $previousColumn) {
                    $table->decimal($category, 5, 3)->default(0)->index()->after($previousColumn);
                });
            }
            $previousColumn = $category;
        }

        foreach ([
            'category_mystery',
            'category_thriller',
            'category_horror',
            'category_adventure',
            'category_historical',
            'category_drama',
            'category_action',
        ] as $category) {
            if (!Schema::hasColumn('dictionary', $category)) {
                Schema::table('dictionary', function (Blueprint $table) use ($category, $previousColumn) {
                    $table->decimal($category, 5, 3)->default(0)->index()->after($previousColumn);
                });
            }
            $previousColumn = $category;
        }
    }

    public function down(): void
    {
        $addedCategories = array_values(array_filter([
            'category_mystery',
            'category_thriller',
            'category_horror',
            'category_adventure',
            'category_historical',
            'category_drama',
            'category_action',
        ], static fn (string $column): bool => Schema::hasColumn('dictionary', $column)));

        if ($addedCategories !== []) {
            Schema::table('dictionary', function (Blueprint $table) use ($addedCategories) {
                $table->dropColumn($addedCategories);
            });
        }

        foreach ([
            'category_space' => 'space',
            'category_fantasy' => 'fantasy',
            'category_tech' => 'tech',
            'category_romance' => 'romance',
            'category_scifi' => 'scifi',
        ] as $category => $legacy) {
            if (Schema::hasColumn('dictionary', $category)
                && !Schema::hasColumn('dictionary', $legacy)) {
                Schema::table('dictionary', function (Blueprint $table) use ($category, $legacy) {
                    $table->renameColumn($category, $legacy);
                });
            }
        }
    }
};
