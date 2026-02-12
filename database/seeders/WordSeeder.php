<?php

namespace Database\Seeders;

use App\Models\Word;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WordSeeder extends Seeder
{
    public function run(): void
    {
        $filePath = database_path('seeders/google-10000-english.txt');

        if (!file_exists($filePath)) {
            $this->command->error("Word list file not found at: {$filePath}");
            return;
        }

        $this->command->info('Importing words from Google 10000 English word list...');

        DB::table('words')->truncate();

        $words = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $rank = 1;
        $batch = [];
        $batchSize = 500;

        foreach ($words as $word) {
            $word = trim(strtolower($word));

            if (empty($word) || !ctype_alpha($word)) {
                continue;
            }

            $batch[] = [
                'word' => $word,
                'rank' => $rank,
                'length' => strlen($word),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $rank++;

            if (count($batch) >= $batchSize) {
                DB::table('words')->insert($batch);
                $batch = [];
                $this->command->info("  Imported {$rank} words...");
            }
        }

        if (!empty($batch)) {
            DB::table('words')->insert($batch);
        }

        $total = DB::table('words')->count();
        $this->command->info("✓ Successfully imported {$total} words!");
    }
}
