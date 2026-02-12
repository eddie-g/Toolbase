<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DictionarySeeder extends Seeder
{
    public function run(): void
    {
        $filePath = database_path('seeders/english-dictionary.txt');

        if (!file_exists($filePath)) {
            $this->command->error("Dictionary file not found at: {$filePath}");
            return;
        }

        $this->command->info('Importing English dictionary words...');

        DB::table('dictionary')->truncate();

        $words = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $batch = [];
        $batchSize = 1000;

        foreach ($words as $word) {
            $word = trim(strtolower($word));

            if (empty($word) || !ctype_alpha($word)) {
                continue;
            }

            $batch[] = [
                'word' => $word,
                'length' => strlen($word),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($batch) >= $batchSize) {
                DB::table('dictionary')->insertOrIgnore($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            DB::table('dictionary')->insertOrIgnore($batch);
        }

        $total = DB::table('dictionary')->count();
        $this->command->info("Imported {$total} words into dictionary table.");
    }
}
