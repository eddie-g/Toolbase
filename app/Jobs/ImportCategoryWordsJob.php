<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportCategoryWordsJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
    public int $timeout = 300;

    /**
     * Maps category slug → word_scores column name.
     */
    private const CATEGORY_COLUMNS = [
        'horror'  => 'category_horror',
        'fantasy' => 'category_fantasy',
        'scifi'   => 'category_scifi',
        'tech'    => 'category_tech',
        'romance' => 'category_romance',
    ];

    private const CHUNK_SIZE = 500;

    public function __construct(public readonly string $category) {}

    public function handle(): void
    {
        $column = self::CATEGORY_COLUMNS[$this->category] ?? null;

        if ($column === null) {
            Log::error('[ImportCategoryWordsJob] Unknown category', ['category' => $this->category]);
            return;
        }

        $path = base_path("python/domain-search/fasttext_{$this->category}.json");

        if (!file_exists($path)) {
            Log::error('[ImportCategoryWordsJob] JSON file not found', ['path' => $path]);
            return;
        }

        $entries = json_decode(file_get_contents($path), true);

        if (!is_array($entries) || empty($entries)) {
            Log::error('[ImportCategoryWordsJob] Empty or invalid JSON', ['path' => $path]);
            return;
        }

        $now   = now()->toDateTimeString();
        $total = 0;

        foreach (array_chunk($entries, self::CHUNK_SIZE) as $chunk) {
            $rows = array_map(fn(array $e) => [
                'word'       => strtolower(trim($e['word'])),
                $column      => $e['similarity'],
                'updated_at' => $now,
                'created_at' => $now,
            ], $chunk);

            // Filter out words that would exceed the column size
            $rows = array_values(array_filter($rows, fn(array $r) => strlen($r['word']) >= 3 && strlen($r['word']) <= 50));

            if (empty($rows)) {
                continue;
            }

            DB::table('word_scores')->upsert(
                $rows,
                ['word'],                      // unique key — no duplicate words
                [$column, 'updated_at'],       // columns to update on conflict
            );

            $total += count($rows);
        }

        Log::info('[ImportCategoryWordsJob] Import complete', [
            'category' => $this->category,
            'column'   => $column,
            'words'    => $total,
        ]);
    }
}
